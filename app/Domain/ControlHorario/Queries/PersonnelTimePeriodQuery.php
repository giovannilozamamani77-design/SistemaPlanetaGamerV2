<?php

namespace App\Domain\ControlHorario\Queries;

use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Domain\Usuarios\Calculators\SalaryPeriodCalculator;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class PersonnelTimePeriodQuery
{
    public function execute(User $actor, ?User $target = null): array
    {
        $actor = User::findOrFail($actor->id);
        $target = User::findOrFail($target?->id ?? $actor->id);
        if ($target->id === $actor->id) {
            Gate::forUser($actor)->authorize('controlhorario.ver_propios');
        } else {
            app(AdministrativeReview::class)->authorize($actor, 'controlhorario.revisar');
        }
        $period = app(SalaryPeriodCalculator::class)->forUser($target, now());
        $base = TimeIncident::where('user_id', $target->id)->where('pending_minutes', '>', 0);
        $pending = (int) (clone $base)->sum('pending_minutes');
        $previous = $period ? (int) (clone $base)->whereHas('scheduledWorkday', fn ($query) => $query->where('work_date', '<', $period['start']))->sum('pending_minutes') : 0;

        return ['period' => $period, 'pending' => $pending, 'previous' => $previous, 'current' => $pending - $previous];
    }
}
