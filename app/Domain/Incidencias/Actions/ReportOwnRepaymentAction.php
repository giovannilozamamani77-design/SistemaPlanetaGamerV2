<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Incidencias\Queries\RepaymentDestinationQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReportOwnRepaymentAction
{
    public function execute(User $actor, int $incidentId, array $data): RecoveryPayment
    {
        $v = Validator::make($data, ['operation_reference' => ['required', 'string', 'max:120', 'regex:/\S/u'], 'request_key' => ['required', 'uuid']])->validate();
        $reference = mb_strtoupper(trim($v['operation_reference']));

        try {
            return DB::transaction(function () use ($actor, $incidentId, $v, $reference): RecoveryPayment {
                $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($user)->authorize('incidencias.declarar_aporte');
                $case = EconomicIncident::whereKey($incidentId)->lockForUpdate()->firstOrFail();
                if ($case->user_id !== $user->id) {
                    throw new AuthorizationException;
                }
                if ($prior = RecoveryPayment::where('request_key', $v['request_key'])->first()) {
                    if ($prior->economic_incident_id !== $case->id || $prior->declared_by !== $user->id || $prior->operation_reference !== $reference) {
                        throw ValidationException::withMessages(['operation_reference' => 'Solicitud ya utilizada con otros datos.']);
                    }

                    return $prior;
                }
                $amount = in_array($case->status, ['DETECTADA', 'EN_REVISION'], true) ? $case->amount_cents : $case->pending_cents;
                if (! in_array($case->status, ['DETECTADA', 'EN_REVISION', 'CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO'], true) || $amount <= 0) {
                    throw ValidationException::withMessages(['operation_reference' => 'Este faltante ya no tiene un importe para reponer.']);
                }
                $destination = app(RepaymentDestinationQuery::class)->execute($user, $case->id, true)->destination;
                $prior = RecoveryPayment::where('destination', $destination)->whereRaw('UPPER(TRIM(operation_reference)) = ?', [$reference])->first();
                if ($prior && $prior->economic_incident_id === $case->id && $prior->declared_by === $user->id && ! $prior->rejected_at) {
                    return $prior;
                }
                if ($prior || GlobalRecovery::where('destination', $destination)->whereRaw('UPPER(TRIM(operation_reference)) = ?', [$reference])->exists()
                    || DB::table('cash_box_liquidations')->where('destination', $destination)->whereRaw('UPPER(TRIM(operation_reference)) = ?', [$reference])->exists()) {
                    throw ValidationException::withMessages(['operation_reference' => 'Este ID ya fue registrado. No se puede usar dos veces.']);
                }
                if (GlobalRecovery::where('user_id', $user->id)->whereNull('confirmed_at')->whereNull('rejected_at')->exists()) {
                    throw ValidationException::withMessages(['operation_reference' => 'Tienes un pago acumulado por verificar. Espera la revisión para usar el saldo actualizado.']);
                }
                if ($case->recoveries()->whereNull('confirmed_at')->whereNull('rejected_at')->where('kind', 'RECUPERACION')->exists()) {
                    throw ValidationException::withMessages(['operation_reference' => 'Ya tienes una reposición de este faltante por verificar. Espera la respuesta de Administración.']);
                }
                $payment = RecoveryPayment::create(['economic_incident_id' => $case->id, 'declared_by' => $user->id, 'paid_by_user_id' => $user->id,
                    'request_key' => $v['request_key'], 'kind' => 'RECUPERACION', 'method' => 'QR', 'amount_cents' => $amount,
                    'operation_reference' => $reference, 'destination' => $destination, 'note' => 'Reposición Yape informada por la responsable; pendiente de verificación administrativa.']);
                app(EconomicHistoryAudit::class)->record($case->id, $user->id, 'REPOSICION_INFORMADA', 'repayment-report:'.$v['request_key'], ['payment_id' => $payment->id, 'amount_cents' => $amount, 'operation_reference' => $reference, 'destination' => $destination]);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['operation_reference' => 'Este ID ya fue registrado. Revisa tu historial antes de repetir el reporte.']);
        }
    }
}
