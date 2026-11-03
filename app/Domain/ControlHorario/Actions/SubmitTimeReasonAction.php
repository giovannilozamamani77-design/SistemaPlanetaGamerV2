<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Data\TimeReasonCodes;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubmitTimeReasonAction
{
    public function execute(User $actor, TimeIncident $target, string $code, string $reason): TimeIncident
    {
        Gate::forUser(User::findOrFail($actor->id))->authorize('controlhorario.solicitar');
        if ($actor->id !== $target->user_id) {
            throw new AuthorizationException;
        }
        if (! TimeReasonCodes::valid($code) || trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Selecciona motivo y escribe explicación.']);
        }

        return DB::transaction(function () use ($actor, $target, $code, $reason): TimeIncident {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $case = TimeIncident::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($actor->id !== $case->user_id) {
                throw new AuthorizationException;
            }
            if (in_array($case->status, ['JUSTIFICADA', 'INVALIDADA', 'PERDONADA', 'PERMISO_AUTORIZADO', 'SIN_RETRASO'], true)) {
                throw ValidationException::withMessages(['reason' => 'Este registro ya está resuelto.']);
            }
            if (in_array($case->status, ['PENDIENTE_APROBACION', 'EN_REVISION'], true) && $case->reason_code === $code && $case->reason === $reason) {
                return $case;
            }
            $previous = $case->only(['status', 'reason_code', 'reason', 'decision_reason', 'pending_minutes']);
            $case->reason_code = $code;
            $case->reason = $reason;
            $case->status = $case->status === 'CONFIRMADA' ? 'EN_REVISION' : 'PENDIENTE_APROBACION';
            $case->save();
            app(TimeHistoryAudit::class)->record($case->user_id, $actor->id, 'MOTIVO_O_REVISION', 'reason:'.Str::uuid(), ['incident_id' => $case->id, 'before' => $previous, 'reason' => $reason, 'code' => $code]);

            return $case;
        });
    }
}
