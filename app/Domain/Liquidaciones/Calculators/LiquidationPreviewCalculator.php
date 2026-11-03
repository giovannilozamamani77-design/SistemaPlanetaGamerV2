<?php

namespace App\Domain\Liquidaciones\Calculators;

use InvalidArgumentException;

class LiquidationPreviewCalculator
{
    public function calculate(array $balances, int $capital, string $source, array $reserved = []): array
    {
        if ($capital < 0 || array_diff_key($reserved, $balances)) {
            throw new InvalidArgumentException('Capital o fondos reservados inválidos.');
        }
        foreach ($balances as $key => $value) {
            if (! is_int($value) || $value < 0 || ! is_int($reserved[$key] ?? 0) || ($reserved[$key] ?? 0) < 0 || ($reserved[$key] ?? 0) > $value) {
                throw new InvalidArgumentException('Los saldos deben ser centavos enteros válidos.');
            }
        }
        $operating = array_sum($balances) - array_sum($reserved);
        $excess = max(0, $operating - $capital);
        $available = max(0, ($balances[$source] ?? 0) - ($reserved[$source] ?? 0));
        $ready = $excess > 0 && isset($balances[$source]) && $available >= $excess;
        $after = $balances;
        if ($ready) {
            $after[$source] -= $excess;
        }

        return ['capital_cents' => $capital, 'operating_total_cents' => $operating, 'amount_cents' => $excess,
            'source_available_cents' => $available, 'can_liquidate' => $ready,
            'shortage_cents' => max(0, $capital - $operating), 'after_balances' => $ready ? $after : null,
            'capital_after_cents' => $ready ? $operating - $excess : $operating,
            'status' => $operating < $capital ? 'FALTANTE' : ($excess === 0 ? 'SIN_EXCEDENTE' : ($ready ? 'DISPONIBLE' : 'ORIGEN_INSUFICIENTE'))];
    }
}
