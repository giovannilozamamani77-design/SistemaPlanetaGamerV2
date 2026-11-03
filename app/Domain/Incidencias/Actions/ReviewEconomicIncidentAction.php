<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewEconomicIncidentAction
{
    public function execute(User $actor, EconomicIncident $target, string $decision, string $reason, ?int $secondAmount = null, bool $exception = false, ?string $pin = null): EconomicIncident
    {
        app(AdministrativeReview::class)->authorize($actor, 'incidencias.confirmar', $target->user_id, $exception, $reason);
        if (in_array($decision, ['ANULAR', 'CONDONAR', 'CARGO_BANCARIO'], true)) {
            app(VerifyPersonalPinAction::class)->execute($actor, $pin ?? '');
            $reason = trim($reason) ?: 'Resolución administrativa: '.$decision;
        }
        if (! in_array($decision, ['CONFIRMAR', 'ANULAR', 'CONDONAR', 'CARGO_BANCARIO'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Escribe motivo y decisión.']);
        }

        return DB::transaction(function () use ($actor, $target, $decision, $reason, $secondAmount): EconomicIncident {
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $case = EconomicIncident::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if (in_array($case->status, ['REPUESTA', 'ANULADA', 'CONDONADA', 'CONDONADA_AUTOMATICA_MENOR', 'CARGO_BANCARIO'], true)) {
                return $case;
            }
            if ($decision === 'CARGO_BANCARIO') {
                if (! in_array(mb_strtolower(trim($case->component)), ['bcp', 'cuenta_bcp'], true) || $case->recoveries()->whereNotNull('confirmed_at')->exists()) {
                    throw ValidationException::withMessages(['reason' => 'Solo cuenta BCP sin recuperaciones aplicadas puede reclasificarse como cargo bancario.']);
                }
                $case->update(['status' => 'CARGO_BANCARIO', 'cause' => 'CARGO_BANCARIO', 'pending_cents' => 0, 'decision' => $reason]);
                app(EconomicHistoryAudit::class)->record($case->id, $actor->id, 'CARGO_BANCARIO_VERIFICADO', 'bank-charge:'.$case->id, ['reason' => $reason, 'amount_cents' => $case->amount_cents]);

                return $case->refresh();
            }
            $before = $case->only(['status', 'pending_cents']);
            if ($decision === 'CONFIRMAR') {
                if (! in_array($case->status, ['DETECTADA', 'EN_REVISION'], true)) {
                    return $case;
                }
                if ($case->amount_cents > ($case->policy_snapshot['high_difference_cents'] ?? 10000) && $secondAmount !== $case->amount_cents) {
                    throw ValidationException::withMessages(['second_amount' => 'La diferencia supera Bs100: vuelve a comprobar e ingresar el importe exacto.']);
                }
                $case->status = 'CONFIRMADA';
                $case->pending_cents = $case->amount_cents;
            } elseif ($decision === 'ANULAR') {
                if ($case->recoveries()->whereNotNull('confirmed_at')->exists()) {
                    throw ValidationException::withMessages(['reason' => 'Hay recuperaciones verificadas: registra su reversa antes de anular el origen.']);
                }
                $case->status = 'ANULADA';
                $case->pending_cents = 0;
            } else {
                if (! in_array($case->status, ['CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO'], true)) {
                    throw ValidationException::withMessages(['reason' => 'Primero confirma la responsabilidad.']);
                }
                $case->forgiven_cents += $case->pending_cents;
                $case->status = 'CONDONADA';
                $case->pending_cents = 0;
            }
            $case->decision = $reason;
            $case->save();
            app(EconomicHistoryAudit::class)->record($case->id, $actor->id, 'FALTANTE_'.$decision, 'review:'.$case->id.':'.$decision, ['before' => $before, 'after' => $case->only(['status', 'pending_cents']), 'reason' => $reason]);

            return $case;
        });
    }
}
