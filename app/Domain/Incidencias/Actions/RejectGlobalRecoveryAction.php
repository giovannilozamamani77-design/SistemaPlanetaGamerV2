<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RejectGlobalRecoveryAction
{
    public function execute(User $actor, GlobalRecovery $target, string $reason, bool $exception = false): GlobalRecovery
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:2000']])->validate();
        $target = GlobalRecovery::findOrFail($target->id);
        app(AdministrativeReview::class)->authorize($actor, 'incidencias.reposiciones.confirmar', $target->user_id, $exception, $reason);

        return DB::transaction(function () use ($actor, $target, $reason, $exception): GlobalRecovery {
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            app(AdministrativeReview::class)->authorize($actor, 'incidencias.reposiciones.confirmar', $target->user_id, $exception, $reason);
            $batch = GlobalRecovery::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($batch->confirmed_at) {
                throw ValidationException::withMessages(['reason' => 'Esta reposición ya fue verificada. No puede rechazarse ni deshacerse desde esta acción.']);
            }
            if ($batch->rejected_at) {
                return $batch;
            }
            $batch->update(['rejected_at' => now(), 'rejected_by' => $actor->id, 'rejection_reason' => $reason]);
            DB::table('user_audits')->insert(['actor_user_id' => $actor->id, 'subject_user_id' => $batch->user_id, 'action' => 'global_recovery_rejected', 'changes' => json_encode(['recovery_id' => $batch->id, 'amount_cents' => $batch->amount_cents, 'operation_reference' => $batch->operation_reference, 'reason' => $reason], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $batch;
        });
    }
}
