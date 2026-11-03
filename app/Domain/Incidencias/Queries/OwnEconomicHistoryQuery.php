<?php

namespace App\Domain\Incidencias\Queries;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class OwnEconomicHistoryQuery
{
    public function execute(User $actor): Builder
    {
        Gate::forUser(User::findOrFail($actor->id))->authorize('incidencias.ver_propios');

        return EconomicIncident::where('user_id', $actor->id)->with('latestRecovery:id,economic_incident_id,rejected_at,rejection_reason,confirmed_at')->withExists(['recoveries as has_pending_report' => fn (Builder $query): Builder => $query->whereNull('confirmed_at')->whereNull('rejected_at')->where('kind', 'RECUPERACION')]);
    }
}
