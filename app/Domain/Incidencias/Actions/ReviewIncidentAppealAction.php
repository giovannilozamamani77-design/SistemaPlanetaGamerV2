<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReviewIncidentAppealAction
{
    public function execute(User $actor, int $incidentId, string $decision, string $reason = '', ?string $pin = null): EconomicIncident
    {
        return DB::transaction(function () use ($actor, $incidentId, $decision, $reason, $pin): EconomicIncident {
            $admin = User::findOrFail($actor->id);
            Gate::forUser($admin)->authorize('incidencias.confirmar');
            abort_unless($admin->hasRole('administrador'), 403);
            $target = EconomicIncident::findOrFail($incidentId);
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $case = EconomicIncident::whereKey($incidentId)->lockForUpdate()->firstOrFail();
            if (! in_array($decision, ['ACEPTAR', 'RECHAZAR'], true) || ! $case->receipt_justified_at) {
                throw ValidationException::withMessages(['decision' => 'Selecciona una apelación presentada.']);
            }
            $prior = $case->policy_snapshot['appeal_decision'] ?? null;
            if ($prior) {
                if ($prior !== $decision) {
                    throw ValidationException::withMessages(['decision' => 'La apelación ya fue resuelta; conserva su historial.']);
                }
                if ($decision === 'ACEPTAR') {
                    app(VerifyPersonalPinAction::class)->execute($admin, $pin ?? '');
                }

                return $case;
            }
            if (! in_array($case->status, ['DETECTADA', 'EN_REVISION', 'CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO'], true)) {
                throw ValidationException::withMessages(['decision' => 'El faltante ya fue resuelto; consulta su historial.']);
            }
            if ($decision === 'ACEPTAR') {
                $case = app(ReviewEconomicIncidentAction::class)->execute($admin, $case, 'ANULAR', $reason, null, false, $pin);
            }
            $reason = trim($reason) ?: ($decision === 'ACEPTAR' ? 'Apelación aceptada por Administración.' : 'Apelación rechazada: se mantiene el faltante.');
            $case->update(['policy_snapshot' => [...$case->policy_snapshot, 'appeal_decision' => $decision, 'appeal_response' => $reason, 'appeal_reviewed_at' => now()->toIso8601String(), 'appeal_reviewed_by' => $admin->id]]);
            app(EconomicHistoryAudit::class)->record($case->id, $admin->id, 'APELACION_'.$decision, 'appeal-review:'.$case->id, ['reason' => $reason, 'decision' => $decision]);

            return $case->refresh();
        });
    }
}
