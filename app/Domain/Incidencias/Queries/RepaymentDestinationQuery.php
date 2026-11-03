<?php

namespace App\Domain\Incidencias\Queries;

use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RepaymentDestinationQuery
{
    public function execute(User $actor, int $incidentId, bool $lock = false): object
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('incidencias.ver_propios');
        $case = EconomicIncident::findOrFail($incidentId);
        if ($case->user_id !== $user->id) {
            app(AdministrativeReview::class)->authorize($user, 'incidencias.ver_todos');
        }
        $key = 'legacy';
        if ($close = CashBoxClose::where('economic_incident_id', $case->id)->first()) {
            $key = CashBoxSession::findOrFail($close->cash_box_session_id)->configuration->settlement_destination_key ?? 'legacy';
        } elseif ($receipt = DB::table('cash_box_receipt_discrepancies')->where('economic_incident_id', $case->id)->first()) {
            $key = CashBoxSession::findOrFail($receipt->cash_box_session_id)->configuration->settlement_destination_key ?? 'legacy';
        }
        $key = app(PersonnelRules::class)->current()['repayment_destination_key'] ?? $key;
        $account = DB::table('cash_box_commission_accounts')->where('account_key', $key)->where('is_demo', false)->latest('id')->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $account) {
            throw ValidationException::withMessages(['operation_reference' => 'Administración debe configurar la cuenta de comisiones para recibir la reposición.']);
        }

        return $account;
    }

    public function quote(User $actor, int $incidentId): array
    {
        try {
            $account = $this->execute($actor, $incidentId);
            $message = null;
        } catch (ValidationException $exception) {
            $account = null;
            $message = $exception->validator->errors()->first();
        }
        $case = EconomicIncident::findOrFail($incidentId);

        return ['account' => $account, 'message' => $message, 'amount' => in_array($case->status, ['DETECTADA', 'EN_REVISION'], true) ? $case->amount_cents : $case->pending_cents];
    }
}
