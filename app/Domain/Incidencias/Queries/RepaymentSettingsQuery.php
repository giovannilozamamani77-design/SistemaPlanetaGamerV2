<?php

namespace App\Domain\Incidencias\Queries;

use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RepaymentSettingsQuery
{
    public function execute(User $actor): array
    {
        app(AdministrativeReview::class)->authorize($actor, 'incidencias.confirmar');
        $accounts = DB::table('cash_box_commission_accounts')->where('is_demo', false)->orderByDesc('id')->get()->unique('account_key');

        return ['destination_key' => app(PersonnelRules::class)->current()['repayment_destination_key'] ?? null, 'options' => $accounts->mapWithKeys(fn ($a) => [$a->account_key => $a->name.' · '.$a->destination.' · '.($a->holder_name ?? 'Titular sin configurar')])->all()];
    }
}
