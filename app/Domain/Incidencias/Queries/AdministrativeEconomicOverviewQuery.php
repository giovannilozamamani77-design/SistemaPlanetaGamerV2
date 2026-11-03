<?php

namespace App\Domain\Incidencias\Queries;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AdministrativeEconomicOverviewQuery
{
    /** @return array{total:int,queue:int,third:int} */
    public function execute(User $actor, string $section): array
    {
        $model = match ($section) {
            'faltantes' => EconomicIncident::class, 'reposiciones' => GlobalRecovery::class, default => abort(403)
        };
        Gate::forUser(User::findOrFail($actor->id))->authorize('viewAny', $model);
        $record = $section === 'faltantes'
            ? $model::query()->selectRaw("COUNT(*) AS total, COALESCE(SUM(CASE WHEN status IN ('DETECTADA','EN_REVISION') THEN 1 ELSE 0 END),0) AS queue, COALESCE(SUM(pending_cents),0) AS third")->first()
            : $model::query()->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN confirmed_at IS NULL AND rejected_at IS NULL THEN 1 ELSE 0 END),0) AS queue, COALESCE(SUM(CASE WHEN confirmed_at IS NOT NULL THEN 1 ELSE 0 END),0) AS third')->first();

        return ['total' => (int) $record->total, 'queue' => (int) $record->queue, 'third' => (int) $record->third];
    }
}
