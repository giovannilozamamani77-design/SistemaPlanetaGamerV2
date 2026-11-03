<?php

namespace App\Domain\Incidencias\Queries;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PendingIncidentAppealsQuery
{
    public function execute(User $actor): Builder
    {
        app(AdministrativeReview::class)->authorize($actor, 'incidencias.confirmar');

        return EconomicIncident::query()->with('user')->whereNotNull('receipt_justified_at')->whereNull('policy_snapshot->appeal_decision')->whereIn('status', ['DETECTADA', 'EN_REVISION', 'CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO']);
    }
}
