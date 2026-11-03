<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AppealEconomicIncidentAction
{
    public function execute(User $actor, int $incidentId, array $data): EconomicIncident
    {
        $v = Validator::make($data, ['text' => ['required', 'string', 'min:3', 'max:2000', 'regex:/\S/u'], 'request_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $incidentId, $v): EconomicIncident {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('incidencias.ver_propios');
            $case = EconomicIncident::whereKey($incidentId)->lockForUpdate()->firstOrFail();
            if ($case->user_id !== $user->id) {
                throw new AuthorizationException;
            }
            $key = 'incident-explanation:'.$v['request_key'];
            if ($prior = DB::table('economic_history_events')->where('event_key', $key)->first()) {
                if ($prior->economic_incident_id !== $case->id || $prior->actor_user_id !== $user->id || (json_decode($prior->details, true)['text'] ?? null) !== $v['text']) {
                    throw ValidationException::withMessages(['text' => 'Esta solicitud ya se utilizó con otros datos.']);
                }

                return $case;
            }
            if (! in_array($case->status, ['DETECTADA', 'EN_REVISION', 'CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO'], true)) {
                throw ValidationException::withMessages(['text' => 'El faltante ya está resuelto; su historial se conserva.']);
            }
            if ($case->receipt_justified_at) {
                throw ValidationException::withMessages(['text' => 'Solo puedes apelar una vez. Administración revisará la explicación enviada.']);
            }
            $case->update(['receipt_justification' => $v['text'], 'receipt_justified_at' => now()]);
            app(EconomicHistoryAudit::class)->record($case->id, $user->id, 'FALTANTE_APELADO', $key, ['text' => $v['text']]);

            return $case;
        });
    }
}
