<?php

namespace App\Domain\Tarjetas\Calculators;

use Illuminate\Validation\ValidationException;

class EntelRechargeCalculator
{
    public function total(int $amount, int $bps): array
    {
        if ($amount < 10000 || $amount > CardSaleCalculator::MAX_CENTS) {
            throw ValidationException::withMessages(['amount' => 'La recarga mínima es Bs 100; utiliza un importe válido con hasta dos decimales.']);
        }
        if ($bps < 0 || $bps > 10000) {
            throw ValidationException::withMessages(['bonus' => 'El porcentaje debe estar entre 0 y 100 %.']);
        }
        $bonus = intdiv($amount * $bps + 5000, 10000);

        return ['bonus_rate_bps' => $bps, 'customer_bonus_cents' => $bonus, 'customer_total_cents' => $amount + $bonus];
    }
}
