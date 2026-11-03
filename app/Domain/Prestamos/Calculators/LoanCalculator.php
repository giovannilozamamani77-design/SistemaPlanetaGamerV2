<?php

namespace App\Domain\Prestamos\Calculators;

use App\Domain\Prestamos\Data\LoanInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LoanCalculator
{
    public function terms(int $capital, int $rate, int $days, string $start): array
    {
        Validator::make(['days' => $days, 'start_date' => $start], ['days' => ['required', 'integer', 'between:1,30'], 'start_date' => ['required', 'date_format:Y-m-d']])->validate();
        if ($capital < 1 || $capital > LoanInput::MAX_CENTS || $rate < 1 || $rate > 999999) {
            throw ValidationException::withMessages(['capital' => 'Capital o tarifa fuera de los límites admitidos.']);
        }
        $denominator = $days <= 15 ? 20000 : 10000;
        $interest = intdiv($capital * $rate + intdiv($denominator, 2), $denominator);
        if ($capital + $interest > LoanInput::MAX_CENTS) {
            throw ValidationException::withMessages(['capital' => 'El total supera el límite admitido.']);
        }

        return ['capital_cents' => $capital, 'rate_bps' => $rate, 'days' => $days, 'interest_cents' => $interest, 'total_cents' => $capital + $interest, 'start_date' => $start, 'due_date' => CarbonImmutable::createFromFormat('!Y-m-d', $start, 'America/La_Paz')->addDays($days - 1)->toDateString()];
    }

    public function status(string $due, string $stored = 'ACTIVO'): string
    {
        if ($stored !== 'ACTIVO') {
            return $stored;
        }$days = CarbonImmutable::parse($due, 'America/La_Paz')->startOfDay()->diffInDays(CarbonImmutable::now('America/La_Paz')->startOfDay(), false);

        return $days >= 8 ? 'PERDIDA_TOTAL' : ($days >= 1 ? 'CON_MULTA' : 'ACTIVO');
    }
}
