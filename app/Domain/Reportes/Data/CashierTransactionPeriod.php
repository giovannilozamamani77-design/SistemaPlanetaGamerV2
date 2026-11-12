<?php

namespace App\Domain\Reportes\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CashierTransactionPeriod
{
    public const OPTIONS = ['today' => 'Hoy', 'yesterday' => 'Ayer', 'last3' => 'Últimos 3 días · incluye hoy', 'last4' => 'Últimos 4 días · incluye hoy', 'week' => 'Esta semana', 'previous_week' => 'Semana anterior', 'month' => 'Este mes', 'previous_month' => 'Mes anterior', 'range' => 'Rango personalizado'];

    public function resolve(string $period, string $from = '', string $until = ''): array
    {
        Validator::make(['period' => $period], ['period' => ['required', Rule::in(array_keys(self::OPTIONS))]])->validate();
        $today = CarbonImmutable::now('America/La_Paz')->startOfDay();
        [$start, $end] = match ($period) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'last3' => [$today->subDays(2), $today],
            'last4' => [$today->subDays(3), $today],
            'week' => [$today->startOfWeek(CarbonInterface::MONDAY), $today],
            'previous_week' => [$today->startOfWeek(CarbonInterface::MONDAY)->subWeek(), $today->startOfWeek(CarbonInterface::MONDAY)->subDay()],
            'month' => [$today->startOfMonth(), $today],
            'previous_month' => [$today->startOfMonth()->subMonth(), $today->startOfMonth()->subDay()],
            default => [null, null],
        };
        $validated = app(ReportPeriod::class)->resolve('range', $today->toDateString(), $start?->toDateString() ?? $from, $end?->toDateString() ?? $until);

        return ['from' => $validated['start'], 'until' => $validated['end']];
    }
}
