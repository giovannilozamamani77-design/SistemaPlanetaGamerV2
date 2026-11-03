<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\TimeHistoryEvent;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Queries\TimeAppealQuery;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewTimeAppealAction
{
    public function execute(User $actor, TimeIncident $target, string $appealKey, string $decision, string $reason, bool $exception = false, string $resolution = 'JUSTIFICAR'): TimeIncident
    {
        $target = TimeIncident::findOrFail($target->id);
        app(AdministrativeReview::class)->authorize($actor, 'controlhorario.revisar', $target->user_id, $exception, $reason);
        if (! in_array($decision, ['ACEPTAR', 'RECHAZAR'], true) || trim($reason) === '' || mb_strlen($reason) > 2000 || ! in_array($resolution, ['JUSTIFICAR', 'INVALIDAR', 'PERDONAR'], true)) {
            throw ValidationException::withMessages(['reason' => 'Elige una resolución y escribe la respuesta (máximo 2000 caracteres).']);
        }
        $resolution = $decision === 'RECHAZAR' ? 'CONFIRMAR' : $resolution;

        return DB::transaction(function () use ($actor, $target, $appealKey, $decision, $reason, $exception, $resolution): TimeIncident {
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $case = TimeIncident::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $key = 'appeal-review:'.$appealKey;
            if ($event = TimeHistoryEvent::where('event_key', $key)->first()) {
                if ($event->user_id !== $case->user_id || $event->details['incident_id'] !== $case->id || $event->details['decision'] !== $decision || $event->details['resolution'] !== $resolution || $event->details['reason'] !== $reason) {
                    throw ValidationException::withMessages(['reason' => 'Esta apelación ya tiene otra respuesta. Actualiza el registro.']);
                }

                return $case;
            }
            $view = app(TimeAppealQuery::class)->execute($actor, $case);
            if (! in_array($case->kind, ['RETRASO', 'FALTA_PARCIAL'], true) || ! in_array($case->status, ['EN_REVISION', 'PENDIENTE_APROBACION'], true) || ! $view['appeal_key'] || $view['appeal_key'] !== $appealKey) {
                throw ValidationException::withMessages(['reason' => 'La apelación cambió o ya fue resuelta. Actualiza el registro.']);
            }
            $before = $case->only(['status', 'pending_minutes', 'reason_code', 'reason', 'decision_reason']);
            $case = app(ReviewTimeIncidentAction::class)->execute($actor, $case, $resolution, $reason, $exception);
            app(TimeHistoryAudit::class)->record($case->user_id, $actor->id, 'APELACION_'.$decision, $key, ['incident_id' => $case->id, 'appeal_key' => $appealKey, 'decision' => $decision, 'resolution' => $resolution, 'reason' => $reason, 'before' => $before, 'pending_minutes' => $case->pending_minutes]);

            return $case;
        });
    }
}
