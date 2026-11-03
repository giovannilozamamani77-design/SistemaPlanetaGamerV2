<?php

namespace App\Domain\ControlHorario\Queries;

use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ScheduleConfiguration
{
    public const FIELDS = ['morning_start', 'morning_end', 'morning_closing_minutes', 'afternoon_start', 'afternoon_end',
        'afternoon_tolerance_minutes', 'afternoon_closing_minutes', 'schedule_enabled', 'cash_box_controls_enabled', 'schedule_cash_box_ids', 'morning_control_enabled', 'afternoon_control_enabled'];

    public function values(array $rules): array
    {
        return array_replace(['morning_start' => '08:00', 'morning_end' => '14:00', 'morning_closing_minutes' => 10,
            'afternoon_start' => '14:00', 'afternoon_end' => '20:00', 'afternoon_closing_minutes' => 10,
            'afternoon_tolerance_minutes' => $rules['tolerance_minutes'] ?? 15, 'schedule_enabled' => true,
            'cash_box_controls_enabled' => (bool) config('cajas.schedule_controls_enabled'), 'schedule_cash_box_ids' => null, 'morning_control_enabled' => true, 'afternoon_control_enabled' => true], $rules);
    }

    public function shifts(array $rules): array
    {
        $v = $this->values($rules);

        return ['manana' => ['start' => $v['morning_start'], 'end' => $v['morning_end'], 'tolerance' => (int) ($v['tolerance_minutes'] ?? 15), 'closing_minutes' => (int) $v['morning_closing_minutes']],
            'tarde' => ['start' => $v['afternoon_start'], 'end' => $v['afternoon_end'], 'tolerance' => (int) $v['afternoon_tolerance_minutes'], 'closing_minutes' => (int) $v['afternoon_closing_minutes']]];
    }

    public function validate(array $values): void
    {
        $v = $this->values($values);
        Validator::make($v, ['morning_start' => ['required', 'date_format:H:i'], 'morning_end' => ['required', 'date_format:H:i'],
            'afternoon_start' => ['required', 'date_format:H:i'], 'afternoon_end' => ['required', 'date_format:H:i']])->validate();
        foreach ($this->shifts($v) as $period => $shift) {
            $start = CarbonImmutable::createFromFormat('!H:i', $shift['start']);
            $end = CarbonImmutable::createFromFormat('!H:i', $shift['end']);
            $duration = (int) $start->diffInMinutes($end, false);
            if ($duration <= 0 || $shift['closing_minutes'] > $duration || $shift['tolerance'] > $duration) {
                throw ValidationException::withMessages([$period === 'manana' ? 'morning_end' : 'afternoon_end' => 'Revisa el turno: fin posterior al inicio y márgenes menores o iguales a su duración.']);
            }
        }
        if ($v['morning_end'] > $v['afternoon_start']) {
            throw ValidationException::withMessages(['afternoon_start' => 'El turno de tarde no puede comenzar antes de terminar el de mañana.']);
        }
    }

    public function periodEnabled(string $period, array $rules): bool
    {
        $values = $this->values($rules);

        return match ($period) {
            'manana' => (bool) $values['morning_control_enabled'],
            'tarde' => (bool) $values['afternoon_control_enabled'],
            default => false,
        };
    }

    public function controlsWorkday(ScheduledWorkday $day): bool
    {
        return $this->periodEnabled($day->period, app(PersonnelRules::class)->at($day->starts_at));
    }

    public function enabled(): bool
    {
        try {
            $rules = app(PersonnelRules::class)->current();

            return (bool) ($rules['cash_box_controls_enabled'] ?? config('cajas.schedule_controls_enabled')) && (bool) ($rules['schedule_enabled'] ?? true);
        } catch (\Throwable $error) {
            report($error);

            return false;
        }
    }

    public function allows(int $boxId): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $v = $this->values(app(PersonnelRules::class)->current());

        return (bool) $v['schedule_enabled'] && ($v['schedule_cash_box_ids'] === null || in_array($boxId, array_map('intval', $v['schedule_cash_box_ids']), true));
    }

    public function summaries(array $values): array
    {
        $out = [];
        foreach ($this->shifts($values) as $period => $shift) {
            $out[$period] = [...$shift, 'enabled' => $this->periodEnabled($period, $values),
                'arrival_until' => CarbonImmutable::createFromFormat('!H:i', $shift['start'])->addMinutes($shift['tolerance'])->format('H:i'),
                'closing_from' => CarbonImmutable::createFromFormat('!H:i', $shift['end'])->subMinutes($shift['closing_minutes'])->format('H:i')];
        }

        return $out;
    }
}
