<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;

class ReconcileTimeCreditAction
{
    /** Internal: caller holds the owner lock inside a transaction. */
    public function execute(int $userId, string $trigger, ?int $actorId = null, bool $manual = false): int
    {
        if (! $manual && ! app(PersonnelRules::class)->current()['automatic_credit']) {
            return 0;
        }
        $available = (int) TimeMovement::where('user_id', $userId)->where('bucket', 'CREDITO')->sum('delta_minutes');
        if ($available <= 0) {
            return 0;
        }
        $total = 0;
        $cases = TimeIncident::where('user_id', $userId)->where('kind', 'RETRASO')->where('status', 'CONFIRMADA')->where('pending_minutes', '>', 0)->orderBy('id')->lockForUpdate()->get();
        foreach ($cases as $case) {
            $used = min($available, $case->pending_minutes);
            if ($used <= 0) {
                break;
            }
            $key = $trigger.':case:'.$case->id;
            if (TimeMovement::where('event_key', $key.':credit')->exists()) {
                continue;
            }
            foreach (['CREDITO' => 'credit', 'RETRASO' => 'debt'] as $bucket => $suffix) {
                TimeMovement::create(['user_id' => $userId, 'time_incident_id' => $case->id, 'actor_user_id' => $actorId, 'bucket' => $bucket, 'delta_minutes' => -$used, 'event_key' => $key.':'.$suffix, 'metadata' => ['automatic' => ! $manual], 'created_at' => now()]);
            }
            $case->pending_minutes -= $used;
            $case->save();
            $available -= $used;
            $total += $used;
        }

        return $total;
    }
}
