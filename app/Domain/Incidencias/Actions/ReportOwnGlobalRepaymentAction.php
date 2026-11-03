<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Domain\Incidencias\Queries\OwnGlobalRepaymentQuoteQuery;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReportOwnGlobalRepaymentAction
{
    public function execute(User $actor, array $data): GlobalRecovery
    {
        $v = Validator::make($data, ['mode' => ['required', 'in:total,partial'], 'amount' => ['required', 'string'], 'operation_reference' => ['required', 'string', 'max:120', 'regex:/\S/u'], 'request_key' => ['required', 'uuid']])->validate();
        $amount = Bolivianos::cents($v['amount']);
        $reference = mb_strtoupper(trim($v['operation_reference']));

        return DB::transaction(function () use ($actor, $v, $amount, $reference): GlobalRecovery {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('incidencias.declarar_aporte');
            if ($prior = GlobalRecovery::where('request_key', $v['request_key'])->first()) {
                if ($prior->user_id !== $user->id || $prior->declared_by !== $user->id || $prior->amount_cents !== $amount || $prior->operation_reference !== $reference) {
                    throw ValidationException::withMessages(['operation_reference' => 'Solicitud ya utilizada con otros datos.']);
                }

                return $prior;
            }
            $quote = app(OwnGlobalRepaymentQuoteQuery::class)->execute($user);
            if ($quote['blocked']) {
                throw ValidationException::withMessages(['operation_reference' => 'Ya tienes un depósito por verificar. Espera la revisión antes de informar otro pago.']);
            }
            if ($amount <= 0 || $amount > $quote['pending'] || ($v['mode'] === 'total' && $amount !== $quote['pending'])) {
                throw ValidationException::withMessages(['amount' => 'Revisa el saldo pendiente actual y el importe que depositaste.']);
            }
            if (! $quote['account']) {
                throw ValidationException::withMessages(['operation_reference' => 'Administración debe configurar el destino de reposiciones.']);
            }

            return app(DeclareGlobalRecoveryAction::class)->execute($user, $user, ['request_key' => $v['request_key'], 'method' => 'QR', 'amount_cents' => $amount, 'operation_reference' => $reference, 'destination' => $quote['account']->destination, 'note' => 'Reposición acumulada informada por la titular; pendiente de verificación.']);
        });
    }
}
