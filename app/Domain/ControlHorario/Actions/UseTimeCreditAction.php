<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\TimeHistoryEvent;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UseTimeCreditAction
{
    public function execute(User $actor, User $target, string $requestKey): int
    {
        $actor = User::findOrFail($actor->id);
        Gate::forUser($actor)->authorize('controlhorario.ver_propios');
        if ($actor->id !== $target->id) {
            app(AdministrativeReview::class)->authorize($actor, 'controlhorario.revisar');
        }
        Validator::make(['key' => $requestKey], ['key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $target, $requestKey): int {
            User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $key = 'manual-credit:'.$requestKey;
            if ($event = TimeHistoryEvent::where('event_key', $key)->first()) {
                if ($event->user_id !== $target->id || $event->actor_user_id !== $actor->id) {
                    throw ValidationException::withMessages(['key' => 'La referencia pertenece a otra operación.']);
                }

                return (int) ($event->details['used_minutes'] ?? 0);
            }
            $used = app(ReconcileTimeCreditAction::class)->execute($target->id, $key, $actor->id, true);
            app(TimeHistoryAudit::class)->record($target->id, $actor->id, 'CREDITO_APLICADO_MANUAL', $key, ['used_minutes' => $used]);

            return $used;
        });
    }
}
