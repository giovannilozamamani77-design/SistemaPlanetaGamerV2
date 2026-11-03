<?php

namespace App\Domain\ControlHorario\Calculators;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

class ScheduleSettingsPreview
{
    public function calculate(array $data): array
    {
        $validator = Validator::make($data, [
            'tolerance_minutes' => ['required', 'integer', 'min:0', 'max:1440'], 'delay_multiplier' => ['required', 'integer', 'in:1,2,3'],
            'multiplier_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'example_start' => ['required', 'date_format:H:i'], 'example_opening' => ['required', 'date_format:H:i'],
            'example_closing_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'example_end' => ['required', 'date_format:H:i'], 'example_closing' => ['required', 'date_format:H:i'],
        ]);
        if ($validator->fails()) {
            return ['valid' => false, 'message' => 'Completa los parámetros y las horas del ejemplo para ver el resultado.'];
        }
        $v = $validator->validated();
        $time = fn (string $key) => CarbonImmutable::createFromFormat('!Y-m-d H:i', '2026-01-06 '.$v[$key], 'America/La_Paz');
        $start = $time('example_start');
        $end = $time('example_end');
        $opening = $time('example_opening');
        $closing = $time('example_closing');
        if ($end <= $start || $closing < $opening) {
            return ['valid' => false, 'message' => 'En este ejemplo el fin debe ser posterior al inicio y el cierre posterior a la apertura.'];
        }
        $calc = app(DelayCalculator::class)->calculate($start, $opening, (int) $v['tolerance_minutes'], (int) $v['delay_multiplier'], filled($v['multiplier_minutes'] ?? null) ? (int) $v['multiplier_minutes'] : null);
        $limit = $end->subMinutes((int) ($v['example_closing_minutes'] ?? 10));

        return [...$calc, 'valid' => true, 'tolerance_until' => $start->addMinutes((int) $v['tolerance_minutes'])->format('H:i'),
            'normal_closing_from' => $limit->format('H:i'), 'early' => $closing < $limit,
            'missing_minutes' => $closing < $limit ? (int) $closing->diffInMinutes($end) : 0];
    }
}
