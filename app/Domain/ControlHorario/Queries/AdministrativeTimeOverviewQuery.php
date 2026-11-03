<?php

namespace App\Domain\ControlHorario\Queries;

use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AdministrativeTimeOverviewQuery
{
    /** @return array{total:int,queue:int,third:int} */
    public function execute(User $actor, string $section): array
    {
        $model = match ($section) {
            'jornadas' => ScheduledWorkday::class, 'retrasos' => TimeIncident::class,
            'permisos' => LeaveRequest::class, default => abort(403),
        };
        Gate::forUser(User::findOrFail($actor->id))->authorize('viewAny', $model);
        $query = $model::query();
        $record = match ($section) {
            'jornadas' => $query->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN work_date = ? THEN 1 ELSE 0 END), 0) AS queue, COALESCE(SUM(CASE WHEN starts_at > ? THEN 1 ELSE 0 END),0) AS third', [now('America/La_Paz')->toDateString(), now()->utc()])->first(),
            'retrasos' => $query->selectRaw("COUNT(*) AS total, COALESCE(SUM(CASE WHEN status IN ('PENDIENTE_MOTIVO','PENDIENTE_APROBACION','EN_REVISION','FALTA_PENDIENTE') THEN 1 ELSE 0 END),0) AS queue, COALESCE(SUM(pending_minutes),0) AS third")->first(),
            'permisos' => $query->selectRaw("COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = 'PENDIENTE' THEN 1 ELSE 0 END),0) AS queue, COALESCE(SUM(CASE WHEN status = 'APROBADA' THEN 1 ELSE 0 END),0) AS third")->first(),
        };

        return ['total' => (int) $record->total, 'queue' => (int) $record->queue, 'third' => (int) $record->third];
    }
}
