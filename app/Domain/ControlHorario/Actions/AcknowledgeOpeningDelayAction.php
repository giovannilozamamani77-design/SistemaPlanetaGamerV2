<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\TimeHistoryEvent;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AcknowledgeOpeningDelayAction
{
    public function execute(User $actor, int $incidentId): void
    {
        DB::transaction(function () use ($actor, $incidentId): void {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('cajas.operar');
            $case = TimeIncident::findOrFail($incidentId);
            if ($case->user_id !== $user->id || ! ($case->calculation['automatic_debt'] ?? false)) {
                throw new AuthorizationException;
            }
            $key = 'opening-delay-seen:'.$case->id;
            if (! TimeHistoryEvent::where('event_key', $key)->exists()) {
                app(TimeHistoryAudit::class)->record($user->id, $user->id, 'AVISO_RETRASO_LEIDO', $key, ['incident_id' => $case->id]);
            }
        });
    }
}
