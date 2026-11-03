<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Notificaciones\Queries\PanelInboxRevision;
use Illuminate\Support\Facades\DB;

class TimeHistoryAudit
{
    /** @param array<string,mixed> $details */
    public function record(int $userId, ?int $actorId, string $action, string $key, array $details): void
    {
        DB::table('time_history_events')->insert(['user_id' => $userId, 'actor_user_id' => $actorId, 'action' => $action, 'event_key' => $key, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        if (in_array($action, ['MOTIVO_O_REVISION', 'APELACION_ACEPTAR', 'APELACION_RECHAZAR'], true)) {
            app(PanelInboxRevision::class)->afterCommit();
        }
    }
}
