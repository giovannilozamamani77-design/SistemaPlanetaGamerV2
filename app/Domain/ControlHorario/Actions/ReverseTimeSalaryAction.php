<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseTimeSalaryAction
{
    public function execute(User $actor, TimeMovement $target, bool $actuallyReversed, string $receipt, bool $exception = false): void
    {
        app(AdministrativeReview::class)->authorize($actor, 'controlhorario.revisar', $target->user_id, $exception, $receipt);
        if (! $actuallyReversed || trim($receipt) === '' || ($target->metadata['kind'] ?? null) !== 'SALARIO_APLICADO') {
            throw ValidationException::withMessages(['receipt' => 'Registra la devolución/corrección salarial externa realmente aplicada.']);
        }
        DB::transaction(function () use ($actor, $target, $receipt): void {
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $original = TimeMovement::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $key = 'reverse:'.$original->event_key;
            if (TimeMovement::where('event_key', $key)->exists()) {
                return;
            }
            $case = TimeIncident::whereKey($original->time_incident_id)->lockForUpdate()->firstOrFail();
            $minutes = -$original->delta_minutes;
            if ($minutes <= 0) {
                throw ValidationException::withMessages(['receipt' => 'Movimiento salarial inválido.']);
            }
            TimeMovement::create(['user_id' => $original->user_id, 'time_incident_id' => $case->id, 'actor_user_id' => $actor->id, 'bucket' => $original->bucket, 'delta_minutes' => $minutes, 'event_key' => $key, 'metadata' => ['kind' => 'SALARIO_REVERTIDO', 'reversal_of' => $original->id, 'receipt' => $receipt, 'amount_cents' => $original->metadata['amount_cents']], 'created_at' => now()]);
            $case->pending_minutes += $minutes;
            $case->save();
            app(TimeHistoryAudit::class)->record($case->user_id, $actor->id, 'SALARIO_REVERTIDO', $key, ['incident_id' => $case->id, 'movement_id' => $original->id, 'minutes' => $minutes, 'receipt' => $receipt]);
        });
    }
}
