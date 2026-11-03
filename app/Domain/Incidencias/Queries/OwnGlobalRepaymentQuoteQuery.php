<?php

namespace App\Domain\Incidencias\Queries;

use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OwnGlobalRepaymentQuoteQuery
{
    public function execute(User $actor): array
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('incidencias.ver_propios');
        Gate::forUser($user)->authorize('incidencias.declarar_aporte');
        $pending = (int) EconomicIncident::where('user_id', $user->id)->whereIn('status', ['CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO'])->sum('pending_cents');
        $blocked = GlobalRecovery::where('user_id', $user->id)->whereNull('confirmed_at')->whereNull('rejected_at')->exists()
            || RecoveryPayment::whereHas('economicIncident', fn ($q) => $q->where('user_id', $user->id))->where('kind', 'RECUPERACION')->whereNull('confirmed_at')->whereNull('rejected_at')->exists();
        $key = app(PersonnelRules::class)->current()['repayment_destination_key'] ?? 'legacy';
        $account = DB::table('cash_box_commission_accounts')->where('account_key', $key)->where('is_demo', false)->latest('id')->first();

        return ['pending' => $pending, 'blocked' => $blocked, 'account' => $account];
    }
}
