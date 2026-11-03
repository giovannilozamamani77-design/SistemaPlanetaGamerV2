<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewRepaymentReportAction
{
    public function execute(User $actor, int $paymentId, string $decision, string $reason, ?int $secondAmount = null, bool $exception = false, bool $received = false): RecoveryPayment
    {
        if (! in_array($decision, ['CONFIRMAR', 'RECHAZAR'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Selecciona la decisión y registra el resultado de la verificación.']);
        }
        $target = RecoveryPayment::findOrFail($paymentId);
        app(AdministrativeReview::class)->authorize($actor, 'incidencias.reposiciones.confirmar', $target->economicIncident->user_id, $exception, $reason);
        if ($decision === 'CONFIRMAR' && ! $received) {
            throw ValidationException::withMessages(['received' => 'Verifica el depósito real mediante el ID antes de confirmar.']);
        }

        return DB::transaction(function () use ($actor, $target, $decision, $reason, $secondAmount, $exception): RecoveryPayment {
            User::whereKey($target->economicIncident->user_id)->lockForUpdate()->firstOrFail();
            app(AdministrativeReview::class)->authorize($actor, 'incidencias.reposiciones.confirmar', $target->economicIncident->user_id, $exception, $reason);
            $case = EconomicIncident::whereKey($target->economic_incident_id)->lockForUpdate()->firstOrFail();
            $payment = RecoveryPayment::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($payment->confirmed_at || $payment->rejected_at) {
                if (($payment->confirmed_at && $decision !== 'CONFIRMAR') || ($payment->rejected_at && $decision !== 'RECHAZAR')) {
                    throw ValidationException::withMessages(['reason' => 'La reposición ya tiene una decisión. Se conserva su historial.']);
                }

                return $payment;
            }
            if ($decision === 'RECHAZAR') {
                $payment->update(['rejected_at' => now(), 'rejected_by' => $actor->id, 'rejection_reason' => $reason]);
                app(EconomicHistoryAudit::class)->record($case->id, $actor->id, 'REPOSICION_RECHAZADA', 'repayment-rejected:'.$payment->id, ['payment_id' => $payment->id, 'reason' => $reason]);

                return $payment;
            }
            if (in_array($case->status, ['DETECTADA', 'EN_REVISION'], true)) {
                app(ReviewEconomicIncidentAction::class)->execute($actor, $case, 'CONFIRMAR', $reason, $secondAmount, $exception);
            }

            return app(ConfirmRecoveryAction::class)->execute($actor, $payment->fresh(), true, $reason, $exception);
        });
    }
}
