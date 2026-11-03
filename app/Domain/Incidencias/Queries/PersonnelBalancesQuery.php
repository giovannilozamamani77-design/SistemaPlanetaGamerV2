<?php

namespace App\Domain\Incidencias\Queries;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Domain\Usuarios\Calculators\SalaryPeriodCalculator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class PersonnelBalancesQuery
{
    public function execute(User $actor, ?User $target = null): array
    {
        $actor = User::findOrFail($actor->id);
        $target ??= $actor;
        if ($actor->id === $target->id) {
            Gate::forUser($actor)->authorize('incidencias.ver_propios');
        } else {
            app(AdministrativeReview::class)->authorize($actor, 'incidencias.ver_todos');
        }
        $target = User::findOrFail($target->id);
        $period = app(SalaryPeriodCalculator::class)->forUser($target, now());
        $base = EconomicIncident::where('user_id', $target->id);
        $sum = (clone $base)->whereNotIn('status', ['DETECTADA', 'EN_REVISION', 'ANULADA', 'CARGO_BANCARIO'])
            ->selectRaw('COALESCE(SUM(amount_cents),0) AS total, COALESCE(SUM(pending_cents),0) AS pending, COALESCE(SUM(forgiven_cents),0) AS forgiven')->first();
        $previous = 0;
        if ($period) {
            $previous = (int) (clone $base)->where('occurred_at', '<', CarbonImmutable::parse($period['start'], 'America/La_Paz')->utc())->sum('pending_cents');
        }
        $paid = RecoveryPayment::whereHas('economicIncident', fn ($q) => $q->where('user_id', $target->id))->whereNotNull('confirmed_at')
            ->whereIn('kind', ['RECUPERACION', 'REVERSA_RECUPERACION'])
            ->selectRaw("COALESCE(SUM(CASE WHEN method <> 'SUELDO' THEN CASE WHEN kind='RECUPERACION' THEN amount_cents ELSE -amount_cents END ELSE 0 END),0) AS received, COALESCE(SUM(CASE WHEN method = 'SUELDO' THEN CASE WHEN kind='RECUPERACION' THEN amount_cents ELSE -amount_cents END ELSE 0 END),0) AS salary")->first();

        $underReview = (int) (clone $base)->whereIn('status', ['DETECTADA', 'EN_REVISION'])->sum('amount_cents');

        return ['under_review' => $underReview, 'period' => $period, 'total' => (int) $sum->total, 'pending' => (int) $sum->pending, 'forgiven' => (int) $sum->forgiven, 'received' => (int) $paid->received, 'salary' => (int) $paid->salary, 'previous' => $previous, 'current' => (int) $sum->pending - $previous];
    }
}
