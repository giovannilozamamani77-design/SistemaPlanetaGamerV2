<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmRecoveryAction
{
    public function execute(User $actor, RecoveryPayment $target, bool $received, string $reason, bool $exception = false): RecoveryPayment
    {
        app(AdministrativeReview::class)->authorize($actor, 'incidencias.reposiciones.confirmar', $target->economicIncident->user_id, $exception, $reason);
        if (! $received || trim($reason) === '') {
            throw ValidationException::withMessages(['received' => 'Debes verificar el ingreso o aplicación real y registrar constancia.']);
        }

        return DB::transaction(function () use ($actor, $target, $reason): RecoveryPayment {
            $owner = $target->economicIncident->user_id;
            User::whereKey($owner)->lockForUpdate()->firstOrFail();
            $case = EconomicIncident::whereKey($target->economic_incident_id)->lockForUpdate()->firstOrFail();
            $payment = RecoveryPayment::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($payment->rejected_at) {
                throw ValidationException::withMessages(['received' => 'Este reporte fue rechazado. Debe conservarse sin aplicar.']);
            }
            if ($payment->confirmed_at) {
                return $payment;
            }
            if ($case->pending_cents <= 0 || ! in_array($case->status, ['CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO'], true)) {
                throw ValidationException::withMessages(['received' => 'La incidencia ya está resuelta o no confirmada.']);
            }
            if (in_array($payment->method, ['DEPOSITO', 'QR'], true) && (! $payment->operation_reference || ! $payment->destination)) {
                throw ValidationException::withMessages(['received' => 'Depósito/QR requiere operación y destino.']);
            }
            if ($payment->method === 'SUELDO' && ! $payment->salary_period) {
                throw ValidationException::withMessages(['received' => 'Registra el periodo salarial de aplicación real.']);
            }
            if ($payment->kind === 'RECUPERACION') {
                if ($payment->amount_cents > $case->pending_cents) {
                    throw ValidationException::withMessages(['received' => 'La recuperación excede el pendiente actual.']);
                }
                $case->pending_cents -= $payment->amount_cents;
                $case->status = $case->pending_cents === 0 ? 'REPUESTA' : 'PAGO_PARCIAL';
                $case->save();
            }
            $payment->update(['confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            app(EconomicHistoryAudit::class)->record($case->id, $actor->id, 'RECEPCION_VERIFICADA', 'receive:'.$payment->id, ['payment_id' => $payment->id, 'kind' => $payment->kind, 'amount_cents' => $payment->amount_cents, 'pending_cents' => $case->pending_cents, 'method' => $payment->method, 'receipt' => $reason]);

            return $payment;
        });
    }
}
