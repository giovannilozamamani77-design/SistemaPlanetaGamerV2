<?php

namespace App\Domain\Cajas\Calculators;

class CashBoxCommissionCalculator
{
    public function calculate(string $type, int $principal, int $bills): int
    {
        if ($type === 'FACTURAS') {
            return $bills * 50;
        }
        if ($type !== 'ACH') {
            return 0;
        }
        if ($principal <= 10000) {
            return 200;
        }
        if ($principal <= 20000) {
            return 300;
        }
        if ($principal <= 40000) {
            return 400;
        }
        if ($principal <= 50000) {
            return 500;
        }

        return intdiv($principal + 4999, 5000) * 50;
    }
}
