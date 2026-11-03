<?php

namespace App\Domain\Tarjetas\Calculators;

use App\Domain\Tarjetas\Data\CardInventoryInput;
use Illuminate\Validation\ValidationException;

class CardSaleCalculator
{
    public const MAX_CENTS = 99999999999;

    public function total(array $lines): array
    {
        $total = 0;
        $units = 0;
        foreach ($lines as $i => $line) {
            $amount = $line['quantity'] * $line['unit_price_cents'];
            if ($amount > self::MAX_CENTS - $total) {
                throw ValidationException::withMessages(['lines.'.$i.'.unit_price' => 'El importe de venta supera el máximo admitido.']);
            }
            $total += $amount;
            $units += $line['quantity'];
        }

        return ['total_cents' => $total, 'total_quantity' => $units];
    }

    public function preview(array $lines): ?array
    {
        $normalized = [];
        foreach ($lines as $line) {
            if (! is_array($line) || filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT) === false || (int) $line['quantity'] < 1 || (int) $line['quantity'] > 1000000) {
                return null;
            }
            try {
                $normalized[] = ['quantity' => (int) $line['quantity'], 'unit_price_cents' => CardInventoryInput::money($line['unit_price'] ?? '', 'unit_price')];
            } catch (ValidationException) {
                return null;
            }
        }
        if (! $normalized) {
            return null;
        }
        try {
            return $this->total($normalized);
        } catch (ValidationException) {
            return null;
        }
    }
}
