<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Models\RecurringWorkShift;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PrepareDatedWorkdayAction
{
    public function execute(User $actor, User $owner, string $date, string $period): ScheduledWorkday
    {
        $actor = User::findOrFail($actor->id);
        if ($actor->id === $owner->id) {
            Gate::forUser($actor)->authorize('controlhorario.solicitar');
        } else {
            Gate::forUser($actor)->authorize('create', ScheduledWorkday::class);
        }
        abort_unless($actor->is_active, 403);
        Validator::make(['work_date' => $date, 'period' => $period], ['work_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('America/La_Paz')->toDateString()], 'period' => ['required', Rule::in(['manana', 'tarde'])]])->validate();

        return DB::transaction(function () use ($actor, $owner, $date, $period): ScheduledWorkday {
            $owner = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            if (! $owner->is_active || ! $owner->hasRole('cajera')) {
                throw ValidationException::withMessages(['work_date' => 'Selecciona una cajera activa.']);
            }
            if ($day = ScheduledWorkday::where('user_id', $owner->id)->where('work_date', $date)->where('period', $period)->first()) {
                return $day;
            }
            $assignment = RecurringWorkShift::where('user_id', $owner->id)->where('period', $period)->current()->where('enabled', true)->first();
            if (! $assignment || CarbonImmutable::parse($date, 'America/La_Paz')->isSunday()) {
                throw ValidationException::withMessages(['work_date' => 'No tienes ese turno habitual para la fecha elegida. Administración puede registrar una excepción.']);
            }
            $shift = app(ScheduleConfiguration::class)->shifts(app(PersonnelRules::class)->current())[$period];
            $start = CarbonImmutable::parse($date.' '.$shift['start'], 'America/La_Paz')->utc();
            $end = CarbonImmutable::parse($date.' '.$shift['end'], 'America/La_Paz')->utc();
            if (ScheduledWorkday::where('user_id', $owner->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                throw ValidationException::withMessages(['period' => 'Hay otro horario excepcional que se superpone. Consulta con Administración.']);
            }
            $day = ScheduledWorkday::create(['user_id' => $owner->id, 'created_by' => $assignment->created_by, 'work_date' => $date, 'period' => $period, 'starts_at' => $start, 'ends_at' => $end]);
            DB::table('workday_audits')->insert(['actor_user_id' => $actor->id, 'scheduled_workday_id' => $day->id, 'action' => 'future_prepared', 'changes' => json_encode(['assignment_id' => $assignment->id, 'reason' => 'Solicitud por fecha o cobertura'], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $day;
        });
    }
}
