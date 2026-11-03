<?php

namespace App\Domain\Cajas\Queries;

use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\ControlHorario\Models\TimeHistoryEvent;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Models\User;

class LateOpeningNoticeQuery
{
    public function execute(User $actor, int $sessionId): ?array
    {
        app(CashBoxClosingStartQuery::class)->execute($actor, $sessionId);
        $session = CashBoxSession::findOrFail($sessionId);
        if (! app(ScheduleConfiguration::class)->allows($session->cash_box_id)) {
            return null;
        }
        $incident = TimeIncident::where('user_id', $actor->id)->where('source_key', 'opening:'.$session->scheduled_workday_id)
            ->where('kind', 'RETRASO')->where('real_minutes', '>', 0)->first();
        if (! $incident || ! ($incident->calculation['automatic_debt'] ?? false) || in_array($incident->status, ['JUSTIFICADA', 'INVALIDADA', 'PERDONADA'], true)
            || TimeHistoryEvent::where('event_key', 'opening-delay-seen:'.$incident->id)->exists()) {
            return null;
        }

        return ['id' => $incident->id, 'real' => $incident->real_minutes, 'weighted' => $incident->nominal_minutes, 'pending' => $incident->pending_minutes,
            'message' => 'La apertura tiene '.$incident->real_minutes.' minuto(s) de retraso. Se registraron '.$incident->nominal_minutes.' minuto(s) acumulados según la regla de tu jornada. Puedes continuar trabajando y explicar el motivo desde tu perfil.'];
    }
}
