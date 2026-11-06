<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Administrative correction of a proven incomplete initial count; never changes actual balances. */
class RectifyOpeningSurplusAction
{
    public function execute(User $actor, int $sessionId, int $expectedSurplus, string $reason, string $requestKey): CashBoxSession
    {
        Validator::make(['reason' => $reason, 'request_key' => $requestKey], ['reason' => ['required', 'string', 'min:10', 'max:2000'], 'request_key' => ['required', 'uuid']])->validate();
        $hash = hash('sha256', json_encode([$sessionId, $expectedSurplus, $reason], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $sessionId, $expectedSurplus, $reason, $requestKey, $hash): CashBoxSession {
            $target = CashBoxSession::findOrFail($sessionId);
            User::whereIn('id', [$actor->id, $target->user_id])->orderBy('id')->lockForUpdate()->get();
            app(AdministrativeReview::class)->authorize($actor, 'cajas.catalogo.gestionar');
            CashBox::whereKey($target->cash_box_id)->lockForUpdate()->firstOrFail();
            $session = CashBoxSession::whereKey($sessionId)->lockForUpdate()->firstOrFail();
            if ($audit = CashBoxAudit::where('request_key', $requestKey)->first()) {
                if ($audit->actor_user_id !== $actor->id || $audit->payload_hash !== $hash || $audit->action !== 'SOBRANTE_INICIAL_RECTIFICADO') {
                    throw ValidationException::withMessages(['rectification' => 'La referencia de rectificación ya fue utilizada.']);
                }

                return $session;
            }
            $previous = $session->previous_session_id ? CashBoxSession::whereKey($session->previous_session_id)->lockForUpdate()->firstOrFail() : null;
            $receipt = DB::table('cash_box_receipt_discrepancies')->where('cash_box_session_id', $sessionId)->lockForUpdate()->first();
            if ($session->status !== 'ABIERTA' || $session->active_slot === null || ! $previous || ! $previous->released_at
                || $previous->previous_session_id !== null || $previous->cash_box_id !== $session->cash_box_id
                || $expectedSurplus <= 0 || ($session->comparison['opening_surplus_cents'] ?? 0) !== $expectedSurplus
                || array_sum($session->opening_balances) !== $session->base_capital_cents
                || array_sum($previous->opening_balances) >= $session->base_capital_cents
                || array_sum($previous->handover_balances ?? []) + $expectedSurplus !== $session->base_capital_cents
                || ! $receipt || (int) $receipt->difference_cents !== $expectedSurplus || $receipt->status !== 'SOBRANTE_REGISTRADO'
                || $receipt->economic_incident_id !== null
                || DB::table('cash_box_operations')->where('cash_box_session_id', $previous->id)->exists()
                || DB::table('cash_box_liquidations')->where('cash_box_id', $session->cash_box_id)->exists()) {
                throw ValidationException::withMessages(['rectification' => 'La caja ya cambió o no corresponde a una primera apertura incompleta. No se modificó nada.']);
            }
            $before = ['comparison' => $session->comparison, 'revision' => $session->revision, 'receipt_status' => $receipt->status];
            $session->update(['comparison' => [...$session->comparison, 'opening_surplus_cents' => 0, 'receipt_classification' => 'RECTIFICADO',
                'opening_rectification' => ['request_key' => $requestKey, 'excluded_surplus_cents' => $expectedSurplus, 'actor_user_id' => $actor->id, 'reason' => $reason]], 'revision' => $session->revision + 1]);
            DB::table('cash_box_receipt_discrepancies')->where('id', $receipt->id)->update(['status' => 'RECTIFICADO']);
            CashBoxAudit::create(['cash_box_id' => $session->cash_box_id, 'actor_user_id' => $actor->id, 'request_key' => $requestKey,
                'action' => 'SOBRANTE_INICIAL_RECTIFICADO', 'payload_hash' => $hash, 'before' => $before,
                'after' => ['session_id' => $sessionId, 'comparison' => $session->comparison, 'revision' => $session->revision, 'receipt_status' => 'RECTIFICADO'],
                'reason' => $reason, 'created_at' => now()]);

            return $session;
        });
    }
}
