<?php

namespace App\Domain\Comisiones\Calculators;

use App\Domain\Accesorios\Models\CommercialRules;

class CommissionCalculator
{
    public function calculate(array $line, ?array $rules = null): int
    {
        $rules ??= CommercialRules::current('COMMISSIONS')['rules'];
        if (! $rules['enabled']) {
            return 0;
        }
        $cost = $line['cost_cents'];
        if (($line['kind'] ?? 'ACCESSORY') !== 'PHONE' && ($line['before_quantity'] ?? 0) > 0) {
            $cost = max($cost, intdiv($line['before_value_cents'] * $line['quantity'] + $line['before_quantity'] - 1, $line['before_quantity']));
        }
        $profit = max(0, $line['total_cents'] - $cost);

        return ($line['kind'] ?? 'ACCESSORY') === 'PHONE'
            ? intdiv($profit * $rules['phone_bps'] + 5000, 10000)
            : min($profit, CommercialRules::bracket($line['unit_price_cents'], $rules['ranges']) * $line['quantity']);
    }
}
