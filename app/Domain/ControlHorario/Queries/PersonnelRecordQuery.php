<?php

namespace App\Domain\ControlHorario\Queries;

use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\Incidencias\Queries\PersonnelBalancesQuery;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PersonnelRecordQuery
{
    public function employee(User $actor, int $id): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->is_active && $actor->hasRole('administrador') && $actor->can('controlhorario.revisar') && $actor->can('incidencias.ver_todos'), 403);

        return User::role(['cajera', 'encargado'])->findOrFail($id);
    }

    public function openings(User $actor, int $id): Builder
    {
        $this->employee($actor, $id);
        $latestIncident = TimeIncident::where('user_id', $id)->where('kind', 'RETRASO')
            ->selectRaw('scheduled_workday_id, MAX(id) AS incident_id')->groupBy('scheduled_workday_id');

        return CashBoxScheduleEvent::query()
            ->where('cash_box_schedule_events.user_id', $id)->where('cash_box_schedule_events.kind', 'OPENING')
            ->leftJoin('scheduled_workdays as day', 'day.id', '=', 'cash_box_schedule_events.scheduled_workday_id')
            ->leftJoinSub($latestIncident, 'latest_delay', 'latest_delay.scheduled_workday_id', '=', 'day.id')
            ->leftJoin('time_incidents as delay', 'delay.id', '=', 'latest_delay.incident_id')
            ->select('cash_box_schedule_events.*', 'day.starts_at as scheduled_start', 'delay.status as delay_status', 'delay.real_minutes', 'delay.nominal_minutes', 'delay.pending_minutes', 'delay.calculation as delay_calculation');
    }

    public function summary(User $actor, int $id): array
    {
        $employee = $this->employee($actor, $id);

        return [
            'employee' => $employee,
            'time' => app(PersonnelTimePeriodQuery::class)->execute($actor, $employee),
            'money' => app(PersonnelBalancesQuery::class)->execute($actor, $employee),
            'credit' => (int) TimeMovement::where('user_id', $id)->where('bucket', 'CREDITO')->sum('delta_minutes'),
        ];
    }
}
