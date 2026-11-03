<?php

namespace App\Domain\ControlHorario\Queries;

use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Domain\ControlHorario\Models\TimeHistoryEvent;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class OwnTimeHistoryQuery
{
    public function incidents(User $actor): Builder
    {
        $this->authorize($actor);

        return TimeIncident::where('user_id', $actor->id)->with('scheduledWorkday');
    }

    public function leaves(User $actor): Builder
    {
        $this->authorize($actor);

        return LeaveRequest::where('user_id', $actor->id)->with(['monthlyLeave', 'scheduledWorkday']);
    }

    public function movements(User $actor): Builder
    {
        $this->authorize($actor);

        return TimeMovement::where('user_id', $actor->id)->with(['incident' => fn ($query) => $query->where('user_id', $actor->id)->with('scheduledWorkday')]);
    }

    public function events(User $actor): Builder
    {
        $this->authorize($actor);

        return TimeHistoryEvent::where('user_id', $actor->id)->with('actor');
    }

    public function balances(User $actor): array
    {
        $this->authorize($actor);

        return TimeMovement::where('user_id', $actor->id)->selectRaw('bucket, SUM(delta_minutes) AS total')->groupBy('bucket')->pluck('total', 'bucket')->map(fn ($value): int => (int) $value)->all();
    }

    public function summary(User $actor): array
    {
        $balances = $this->balances($actor);
        $used = (int) $this->movements($actor)->where('bucket', 'CREDITO')->where('delta_minutes', '<', 0)
            ->whereNotNull('metadata->automatic')->sum('delta_minutes');

        return ['pending' => $balances['RETRASO'] ?? 0, 'available' => $balances['CREDITO'] ?? 0, 'used' => -$used];
    }

    public function overview(User $actor): array
    {
        $summary = $this->summary($actor);
        $early = (int) TimeMovement::where('user_id', $actor->id)->where('bucket', 'FALTA_REAL')
            ->whereHas('incident', fn ($query) => $query->where('user_id', $actor->id)->where('kind', 'FALTA_PARCIAL'))->sum('delta_minutes');

        return [...$summary, 'opening' => $summary['pending'], 'early' => $early, 'total' => $summary['pending'] + $early];
    }

    private function authorize(User $actor): void
    {
        Gate::forUser(User::findOrFail($actor->id))->authorize('controlhorario.ver_propios');
    }
}
