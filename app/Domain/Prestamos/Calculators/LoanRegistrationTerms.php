<?php

namespace App\Domain\Prestamos\Calculators;

use App\Domain\Prestamos\Data\LoanInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoanRegistrationTerms
{
    public function schedule(array $data): array
    {
        $mode = $data['schedule_mode'] ?? 'AUTO';
        Validator::make(['schedule_mode' => $mode] + $data, ['schedule_mode' => ['required', Rule::in(['AUTO', 'MANUAL'])], 'start_date' => ['required', 'date_format:Y-m-d']])->validate();
        if ($mode === 'MANUAL') {
            Validator::make($data, ['due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date']])->validate();
            $days = (int) CarbonImmutable::parse($data['start_date'], 'America/La_Paz')->diffInDays(CarbonImmutable::parse($data['due_date'], 'America/La_Paz'), false) + 1;

            return ['days' => $days, 'start_date' => $data['start_date'], 'due_date' => $data['due_date'], 'schedule_mode' => $mode];
        }
        Validator::make($data, ['days' => ['required', 'integer', 'between:1,30']])->validate();

        return ['days' => (int) $data['days'], 'start_date' => $data['start_date'], 'due_date' => CarbonImmutable::parse($data['start_date'], 'America/La_Paz')->addDays((int) $data['days'] - 1)->toDateString(), 'schedule_mode' => $mode];
    }

    public function calculate(array $data, int $rate): array
    {
        $schedule = $this->schedule($data);
        $mode = $data['interest_mode'] ?? 'AUTO';
        Validator::make(['interest_mode' => $mode], ['interest_mode' => ['required', Rule::in(['AUTO', 'MANUAL'])]])->validate();
        $capital = LoanInput::money($data['capital'] ?? '', 'capital');
        if ($capital > LoanInput::MAX_CENTS) {
            throw ValidationException::withMessages(['capital' => 'El capital supera el límite permitido.']);
        }
        if ($mode === 'MANUAL') {
            $interest = LoanInput::money($data['manual_interest'] ?? '', 'manual_interest', true);
        } else {
            if ($schedule['days'] > 30) {
                throw ValidationException::withMessages(['interest_mode' => 'Para un plazo mayor de 30 días registra el interés manual; no hay una tarifa automática definida para ese plazo.']);
            }
            $interest = app(LoanCalculator::class)->terms($capital, $rate, $schedule['days'], $schedule['start_date'])['interest_cents'];
        }
        if ($interest > LoanInput::MAX_CENTS || $capital + $interest > LoanInput::MAX_CENTS) {
            throw ValidationException::withMessages(['manual_interest' => 'El total supera el límite permitido.']);
        }

        return $schedule + ['capital_cents' => $capital, 'rate_bps' => $rate, 'interest_cents' => $interest, 'total_cents' => $capital + $interest, 'interest_mode' => $mode];
    }
}
