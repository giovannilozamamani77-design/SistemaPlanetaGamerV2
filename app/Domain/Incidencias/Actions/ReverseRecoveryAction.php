<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseRecoveryAction
{
    public function execute(User $actor, RecoveryPayment $target, bool $returned, string $receipt, bool $exception = false): RecoveryPayment
    {
        $target = RecoveryPayment::findOrFail($target->id);
        app(AdministrativeReview::class)->authorize($actor, 'incidencias.reposiciones.confirmar', $target->economicIncident->user_id, $exception, $receipt);
        if (! $returned || ! $target->confirmed_at || $target->kind !== 'RECUPERACION' || trim($receipt) === '') {
            throw ValidationException::withMessages(['receipt' => 'Registra la reversa real de una recuperación verificada.']);
        }

        return DB::transaction(function () use ($actor, $target, $receipt): RecoveryPayment {
            $owner = $target->economicIncident->user_id;
            User::whereKey($owner)->lockForUpdate()->firstOrFail();
            $case = EconomicIncident::whereKey($target->economic_incident_id)->lockForUpdate()->firstOrFail();
            $key = 'reverse-recovery:'.$target->id;
            if ($existing = RecoveryPayment::where('request_key', $key)->first()) {
                return $existing;
            }
            if (in_array($case->status, ['ANULADA', 'CONDONADA', 'CONDONADA_AUTOMATICA_MENOR'], true)) {
                throw ValidationException::withMessages(['receipt' => 'La deuda fue anulada/condonada; requiere resolución contable del caso antes de reactivarla.']);
            }
            $reversal = RecoveryPayment::create(['economic_incident_id' => $case->id, 'declared_by' => $actor->id, 'paid_by_user_id' => $target->paid_by_user_id, 'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'request_key' => $key, 'kind' => 'REVERSA_RECUPERACION', 'method' => $target->method, 'amount_cents' => $target->amount_cents, 'salary_period' => $target->salary_period, 'note' => $receipt]);
            $case->pending_cents += $target->amount_cents;
            $case->status = $case->pending_cents === $case->amount_cents ? 'CONFIRMADA' : 'PAGO_PARCIAL';
            $case->save();
            app(EconomicHistoryAudit::class)->record($case->id, $actor->id, 'RECUPERACION_REVERTIDA', $key, ['payment_id' => $target->id, 'reversal_id' => $reversal->id, 'amount_cents' => $target->amount_cents, 'pending_cents' => $case->pending_cents, 'receipt' => $receipt]);

            return $reversal;
        });
    }
}
