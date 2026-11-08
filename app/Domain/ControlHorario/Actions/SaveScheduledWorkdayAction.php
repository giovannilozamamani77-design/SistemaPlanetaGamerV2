<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveScheduledWorkdayAction
{
    /** @param array<string,mixed> $data */
    public function execute(User $actor, array $data, ?ScheduledWorkday $target = null): ScheduledWorkday
    {
        $admin = User::findOrFail($actor->id);
        Gate::forUser($admin)->authorize($target ? 'update' : 'create', $target ?? ScheduledWorkday::class);
        if (! $target && isset($data['period'])) {
            $shift = app(ScheduleConfiguration::class)->shifts(app(PersonnelRules::class)->current())[$data['period']] ?? null;
            if ($shift) {
                $data += ['starts_time' => $shift['start'], 'ends_time' => $shift['end']];
            }
        }
        $v = Validator::make($data, [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'period' => ['required', Rule::in(['manana', 'tarde'])],
            'starts_time' => ['required', 'date_format:H:i'],
            'ends_time' => ['required', 'date_format:H:i'],
        ])->validate();
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $v['work_date'].' '.$v['starts_time'], 'America/La_Paz')->utc();
        $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', $v['work_date'].' '.$v['ends_time'], 'America/La_Paz')->utc();
        if ($end <= $start) {
            throw ValidationException::withMessages(['ends_time' => 'El fin debe ser posterior al inicio.']);
        }

        return DB::transaction(function () use ($admin, $target, $v, $start, $end): ScheduledWorkday {
            $user = User::whereKey($v['user_id'])->lockForUpdate()->firstOrFail();
            if (! $user->is_active || ! $user->hasAnyRole(['cajera', 'encargado'])) {
                throw ValidationException::withMessages(['user_id' => 'Selecciona una cajera o encargada activa.']);
            }
            $record = $target ? ScheduledWorkday::whereKey($target->id)->lockForUpdate()->firstOrFail() : new ScheduledWorkday;
            if ($record->exists && ($record->attendance()->exists() || $record->starts_at <= now())) {
                throw ValidationException::withMessages(['work_date' => 'Esta jornada ya comenzó o tiene asistencia; conserva su historial.']);
            }
            $query = ScheduledWorkday::where('user_id', $user->id)->when($record->exists, fn ($q) => $q->whereKeyNot($record->id));
            if ((clone $query)->where('work_date', $v['work_date'])->where('period', $v['period'])->exists()) {
                throw ValidationException::withMessages(['period' => 'Ya existe esta jornada para la cajera.']);
            }
            if ((clone $query)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['starts_time' => 'Las jornadas de la cajera no deben superponerse.']);
            }
            $before = $record->exists ? $record->only(['user_id', 'work_date', 'period', 'starts_at', 'ends_at']) : null;
            $record->user_id = $user->id;
            $record->work_date = $v['work_date'];
            $record->period = $v['period'];
            $record->starts_at = $start;
            $record->ends_at = $end;
            if (! $record->exists) {
                $record->created_by = $admin->id;
            }
            if (! $record->isDirty()) {
                return $record;
            }
            $record->save();
            DB::table('workday_audits')->insert([
                'actor_user_id' => $admin->id, 'scheduled_workday_id' => $record->id, 'action' => $before === null ? 'scheduled' : 'rescheduled',
                'changes' => json_encode(['before' => $before, 'after' => $record->only(['user_id', 'work_date', 'period', 'starts_at', 'ends_at'])], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $record;
        });
    }
}
