<?php

namespace App\Domain\Incidencias\Actions;

use Illuminate\Support\Facades\DB;

class EconomicHistoryAudit
{
    /** @param array<string,mixed> $details */
    public function record(int $incidentId, ?int $actorId, string $action, string $key, array $details): void
    {
        DB::table('economic_history_events')->insert(['economic_incident_id' => $incidentId, 'actor_user_id' => $actorId, 'action' => $action, 'event_key' => $key, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
