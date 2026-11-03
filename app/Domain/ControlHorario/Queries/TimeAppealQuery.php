<?php

namespace App\Domain\ControlHorario\Queries;

use App\Domain\ControlHorario\Models\TimeHistoryEvent;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class TimeAppealQuery
{
    public function execute(User $actor, TimeIncident $target): array
    {
        $case = TimeIncident::findOrFail($target->id);
        if ($actor->id === $case->user_id) {
            Gate::forUser(User::findOrFail($actor->id))->authorize('controlhorario.ver_propios');
        } else {
            app(AdministrativeReview::class)->authorize($actor, 'controlhorario.revisar');
        }
        $base = TimeHistoryEvent::where('user_id', $case->user_id)->where(fn ($query) => $query
            ->where('details->incident_id', $case->id)
            ->orWhereIn('event_key', ['review:'.$case->id.':CONFIRMAR', 'review:'.$case->id.':JUSTIFICAR', 'review:'.$case->id.':INVALIDAR', 'review:'.$case->id.':PERDONAR', 'forgive-time:'.$case->id])
            ->orWhere('event_key', 'like', 'ratify:'.$case->id.':%'));
        $appeal = (clone $base)->where('action', 'MOTIVO_O_REVISION')->latest('id')->first();

        return ['case' => $case, 'appeal_key' => $appeal?->event_key,
            'events' => $base->with('actor')->latest('id')->limit(50)->get()];
    }
}
