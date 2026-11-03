<?php

namespace App\Domain\Incidencias\Queries;

use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PendingRepaymentReportsQuery
{
    public function execute(User $actor): Builder
    {
        app(AdministrativeReview::class)->authorize($actor, 'incidencias.reposiciones.confirmar');

        return RecoveryPayment::whereNull('confirmed_at')->whereNull('rejected_at')->where('kind', 'RECUPERACION')->with(['economicIncident.user']);
    }
}
