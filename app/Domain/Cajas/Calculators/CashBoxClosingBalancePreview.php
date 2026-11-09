<?php

namespace App\Domain\Cajas\Calculators;

use App\Support\Bolivianos;

/** A read-only preview, never a recorded count or closing decision. */
class CashBoxClosingBalancePreview
{
    public function calculate(array $balances, array $expected, bool $decimalComma = true): array
    {
        $expectedTotal = (int) array_sum($expected);
        $invalid = ['valid' => false, 'total_cents' => null, 'expected_cents' => $expectedTotal, 'difference_cents' => null, 'difference_absolute_cents' => null, 'kind' => 'invalid'];
        if (array_diff_key($balances, $expected)) {
            return $invalid;
        }
        $total = 0;
        $components = [];
        foreach ($expected as $code => $unused) {
            $raw = $balances[$code] ?? '';
            $raw = $raw === '' ? '0' : $raw;
            if ($decimalComma && is_string($raw)) {
                $raw = str_replace(',', '.', $raw);
            }
            if ((! is_string($raw) && ! is_int($raw)) || ! preg_match('/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/', (string) $raw)) {
                return $invalid;
            }
            $components[$code] = Bolivianos::cents((string) $raw);
            $total += $components[$code];
        }
        $difference = $total - $expectedTotal;

        return ['valid' => true, 'component_cents' => $components, 'total_cents' => $total, 'expected_cents' => $expectedTotal, 'difference_cents' => $difference, 'difference_absolute_cents' => abs($difference), 'kind' => $difference < 0 ? 'shortage' : ($difference > 0 ? 'surplus' : 'balanced')];
    }
}
