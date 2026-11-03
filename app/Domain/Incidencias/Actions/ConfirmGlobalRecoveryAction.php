<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmGlobalRecoveryAction
{
    public function execute(User $actor, GlobalRecovery $target, bool $received, string $receipt, bool $exception = false): GlobalRecovery
    {
        $target = GlobalRecovery::findOrFail($target->id);
        app(AdministrativeReview::class)->authorize($actor, 'incidencias.reposiciones.confirmar', $target->user_id, $exception, $receipt);
        if (! $received || trim($receipt) === '') {
            throw ValidationException::withMessages(['received' => 'Verifica recepción o aplicación salarial real y escribe constancia.']);
        }

        return DB::transaction(function () use ($actor, $target, $receipt, $exception): GlobalRecovery {
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $batch = GlobalRecovery::whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(AdministrativeReview::class)->authorize($actor, 'incidencias.reposiciones.confirmar', $batch->user_id, $exception, $receipt);
            if ($batch->rejected_at) {
                throw ValidationException::withMessages(['receipt' => 'Esta reposición fue rechazada. La titular debe informar un nuevo reporte con el ID correcto.']);
            }
            if ($batch->confirmed_at) {
                return $batch;
            }
            $cases = EconomicIncident::where('user_id', $batch->user_id)->whereIn('status', ['CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO'])->where('pending_cents', '>', 0)->orderBy('id')->lockForUpdate()->get();
            if ($cases->sum('pending_cents') < $batch->amount_cents) {
                throw ValidationException::withMessages(['amount' => 'La deuda cambió; revisa el importe antes de confirmar.']);
            }
            $remaining = $batch->amount_cents;
            foreach ($cases as $case) {
                if ($remaining === 0) {
                    break;
                }
                $used = min($remaining, $case->pending_cents);
                $payment = RecoveryPayment::create([
                    'global_recovery_id' => $batch->id, 'economic_incident_id' => $case->id, 'declared_by' => $batch->declared_by, 'paid_by_user_id' => $batch->user_id,
                    'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'request_key' => 'global:'.$batch->id.':case:'.$case->id,
                    'kind' => 'RECUPERACION', 'method' => $batch->method, 'amount_cents' => $used, 'salary_period' => $batch->salary_period, 'note' => $receipt,
                ]);
                $case->pending_cents -= $used;
                $case->status = $case->pending_cents === 0 ? 'REPUESTA' : 'PAGO_PARCIAL';
                $case->save();
                $remaining -= $used;
                app(EconomicHistoryAudit::class)->record($case->id, $actor->id, 'RECEPCION_GLOBAL_VERIFICADA', 'global:'.$batch->id.':case:'.$case->id, ['global_recovery_id' => $batch->id, 'payment_id' => $payment->id, 'amount_cents' => $used, 'receipt' => $receipt]);
            }
            $batch->update(['confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            DB::table('user_audits')->insert(['actor_user_id' => $actor->id, 'subject_user_id' => $batch->user_id, 'action' => 'global_recovery_received', 'changes' => json_encode(['recovery_id' => $batch->id, 'amount_cents' => $batch->amount_cents, 'receipt' => $receipt], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $batch;
        });
    }
}
