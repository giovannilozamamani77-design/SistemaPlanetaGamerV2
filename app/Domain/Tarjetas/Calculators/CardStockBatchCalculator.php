<?php

namespace App\Domain\Tarjetas\Calculators;

use App\Domain\Tarjetas\Data\CardInventoryInput;
use Illuminate\Validation\ValidationException;

class CardStockBatchCalculator
{
    public const NO_CASH = 'No se tiene efectivo disponible para esta compra. Contacta con Administración.';

    public function normalize(array $raw, bool $delivery): array
    {
        $out = [];
        $total = 0;
        $units = 0;
        foreach ($raw as $key => $line) {
            $q = $line['quantity'] ?? 0;
            if ($q === '' || $q === null) {
                $q = 0;
            }
            if ((! is_int($q) && ! is_string($q)) || filter_var($q, FILTER_VALIDATE_INT) === false || (int) $q < 0 || (int) $q > 1000000) {
                throw ValidationException::withMessages(['lines.'.$key.'.quantity' => 'Ingresa una cantidad entera entre 0 y 1000000.']);
            }
            $q = (int) $q;
            if ($q === 0) {
                continue;
            }
            $price = $line['unit_price'] ?? null;
            $cents = $delivery && ! filled($price) ? null : CardInventoryInput::money($price, 'lines.'.$key.'.unit_price');
            $cost = $cents === null ? 0 : $cents * $q;
            if ($cost > CardSaleCalculator::MAX_CENTS - $total) {
                throw ValidationException::withMessages(['lines' => 'El importe supera el máximo admitido.']);
            }
            $total += $cost;
            $units += $q;
            $out[] = ['product_id' => (int) $line['product_id'], 'quantity' => $q, 'unit_price_cents' => $cents];
        }
        if (! $out) {
            throw ValidationException::withMessages(['lines' => 'Ingresa la cantidad de al menos un corte.']);
        }
        usort($out, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);

        return ['lines' => $out, 'total_cents' => $delivery ? null : $total, 'total_quantity' => $units];
    }

    public function preview(array $raw, bool $delivery, ?int $cash): ?array
    {
        try {
            $p = $this->normalize($raw, $delivery);

            return ['total_cents' => $p['total_cents'], 'total_quantity' => $p['total_quantity'], 'enough_cash' => $delivery || ($cash !== null && $cash >= $p['total_cents'])];
        } catch (ValidationException) {
            return null;
        }
    }
}
