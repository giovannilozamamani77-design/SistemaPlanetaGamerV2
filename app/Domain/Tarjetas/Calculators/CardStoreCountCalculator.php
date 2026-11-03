<?php

namespace App\Domain\Tarjetas\Calculators;

use App\Domain\Tarjetas\Data\CardInventoryInput;
use Illuminate\Validation\ValidationException;

class CardStoreCountCalculator
{
    public function compare(array $expected, array $data, bool $requireSurplusReason = true): array
    {
        $cash = CardInventoryInput::money(filled($data['cash'] ?? null) ? $data['cash'] : '0', 'cash', true);
        if ($cash > CardSaleCalculator::MAX_CENTS) {
            throw ValidationException::withMessages(['cash' => 'El efectivo supera el máximo admitido.']);
        }
        $raw = (array) ($data['stocks'] ?? []);
        $seen = [];
        $lines = [];
        $shortage = max(0, $expected['cash_total_cents'] - $cash);
        $surplus = $cash > $expected['cash_total_cents'];
        foreach ($raw as $key => $line) {
            $id = $line['product_id'] ?? null;
            if (! is_numeric($id) || isset($seen[(int) $id])) {
                throw ValidationException::withMessages(['stocks' => 'Los cortes deben aparecer una sola vez.']);
            }$seen[(int) $id] = ['key' => $key, 'quantity' => $line['quantity'] ?? null];
        }
        if (array_diff(array_keys($seen), array_keys($expected['products'])) || array_diff(array_keys($expected['products']), array_keys($seen))) {
            throw ValidationException::withMessages(['stocks' => 'El catálogo cambió. Actualiza el conteo e incluye todos los cortes.']);
        }
        foreach ($expected['products'] as $id => $p) {
            $row = $seen[$id];
            $q = filled($row['quantity']) ? $row['quantity'] : 0;
            if ((! is_int($q) && ! is_string($q)) || filter_var($q, FILTER_VALIDATE_INT) === false || (int) $q < 0 || (int) $q > 1000000) {
                throw ValidationException::withMessages(['stocks.'.$row['key'].'.quantity' => 'Cuenta cada corte con un entero de 0 a 1000000.']);
            }$q = (int) $q;
            $missing = max(0, $p['expected_quantity'] - $q);
            $amount = $missing * $p['face_value_cents'];
            if ($amount > CardSaleCalculator::MAX_CENTS - $shortage) {
                throw ValidationException::withMessages(['stocks' => 'La diferencia supera el máximo admitido.']);
            }$shortage += $amount;
            $surplus = $surplus || $q > $p['expected_quantity'];
            $lines[] = ['product_id' => $id, 'label' => $p['label'], 'company' => $p['company'], 'face_value_cents' => $p['face_value_cents'], 'expected' => $p['expected_quantity'], 'counted' => $q, 'missing' => $missing, 'shortage_cents' => $amount, 'reserved_expected' => $p['reserved_quantity'], 'reserved_counted' => min($q, $p['reserved_quantity']) + max(0, $q - $p['expected_quantity']), 'available_counted' => $q - min($q, $p['reserved_quantity']) - max(0, $q - $p['expected_quantity'])];
        }
        if ($requireSurplusReason && $surplus && ! filled($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'Explica brevemente el sobrante para dejarlo reservado.']);
        }

        return ['cash' => $cash, 'cash_missing' => max(0, $expected['cash_total_cents'] - $cash), 'cash_surplus' => max(0, $cash - $expected['cash_total_cents']), 'cash_reserved_expected' => $expected['cash_reserved_cents'], 'cash_reserved_counted' => min($cash, $expected['cash_reserved_cents']) + max(0, $cash - $expected['cash_total_cents']), 'cash_available_counted' => $cash - min($cash, $expected['cash_reserved_cents']) - max(0, $cash - $expected['cash_total_cents']), 'shortage_cents' => $shortage, 'lines' => $lines];
    }
}
