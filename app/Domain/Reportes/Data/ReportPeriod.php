<?php

namespace App\Domain\Reportes\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReportPeriod
{
    public function resolve(string $mode, string $date, ?string $from = null, ?string $until = null): array
    {
        $today = now('America/La_Paz')->toDateString();
        $dateRules = ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.$today];
        $rules = ['periodMode' => ['required', Rule::in(['day', 'week', 'month', 'range'])]];
        $rules += $mode === 'range' ? ['rangeFrom' => $dateRules, 'rangeUntil' => [...$dateRules, 'after_or_equal:rangeFrom']] : ['date' => $dateRules];
        Validator::make(['periodMode' => $mode, 'date' => $date, 'rangeFrom' => $from, 'rangeUntil' => $until], $rules,
            ['date.*' => 'Selecciona un día válido, hasta hoy, en hora de Bolivia.', 'periodMode.*' => 'Selecciona Día, Semana, Mes o Rango.', 'rangeFrom.*' => 'Selecciona una fecha inicial válida, hasta hoy.', 'rangeUntil.*' => 'La fecha final debe ser válida, no anterior al inicio ni posterior a hoy.'])->validate();
        $anchor = CarbonImmutable::createFromFormat('!Y-m-d', $mode === 'range' ? $from : $date, 'America/La_Paz');
        $start = match ($mode) {
            'week' => $anchor->startOfWeek(CarbonInterface::MONDAY),
            'month' => $anchor->startOfMonth(),
            default => $anchor,
        };
        $end = match ($mode) {
            'week' => $start->addDays(6),
            'month' => $start->addMonth()->subDay(),
            'range' => CarbonImmutable::createFromFormat('!Y-m-d', $until, 'America/La_Paz'),
            default => $start,
        };
        if ($end->toDateString() > $today) {
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $today, 'America/La_Paz');
        }
        $previousEnd = $start->subDay();
        $previousStart = match ($mode) {
            'week' => $start->subWeek(),
            'month' => $previousEnd->startOfMonth(),
            default => $start->subDays((int) $start->diffInDays($end) + 1),
        };

        return ['mode' => $mode, 'start' => $start->toDateString(), 'end' => $end->toDateString(),
            'previous_start' => $previousStart->toDateString(), 'previous_end' => $previousEnd->toDateString(),
            'label' => $this->label($start->toDateString(), $end->toDateString()),
            'previous_label' => $this->label($previousStart->toDateString(), $previousEnd->toDateString()),
            'comparison_available' => $previousStart->toDateString() >= '1900-01-01', 'is_current' => $end->toDateString() === $today];
    }

    public function label(string $start, string $end): string
    {
        $first = CarbonImmutable::parse($start)->format('d/m/Y');

        return $start === $end ? $first : $first.' — '.CarbonImmutable::parse($end)->format('d/m/Y');
    }
}
