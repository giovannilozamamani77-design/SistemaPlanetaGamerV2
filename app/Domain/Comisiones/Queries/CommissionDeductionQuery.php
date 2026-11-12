<?php

namespace App\Domain\Comisiones\Queries;

use App\Domain\Comisiones\Models\CommissionDeduction;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class CommissionDeductionQuery
{
    public function authorize(User $actor): void
    {
        app(AdminCommissionsQuery::class)->authorize($actor);
        Gate::forUser(User::findOrFail($actor->id))->authorize('controlhorario.revisar');
    }

    public function incidents(User $actor, int $userId): Builder
    {
        $this->authorize($actor);

        return TimeIncident::where('user_id', $userId)->where('status', 'CONFIRMADA')
            ->whereIn('kind', ['RETRASO', 'FALTA', 'FALTA_PARCIAL'])->where('pending_minutes', '>', 0)
            ->whereNotIn('id', CommissionDeduction::select('time_incident_id'));
    }

    public function history(User $actor, int $userId): Builder
    {
        app(CommissionPaymentQuery::class)->authorize($actor, $userId);

        return CommissionDeduction::where('user_id', $userId)->with(['incident.scheduledWorkday', 'reversal']);
    }

    public function total(User $actor, int $userId): int
    {
        app(CommissionPaymentQuery::class)->authorize($actor, $userId);

        return (int) CommissionDeduction::active()->where('user_id', $userId)->sum('amount_cents');
    }
}
