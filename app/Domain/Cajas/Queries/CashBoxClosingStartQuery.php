<?php

namespace App\Domain\Cajas\Queries;

use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Read-only preparation; never recovers past closes or drafts. */
class CashBoxClosingStartQuery
{
    public function execute(User $actor, int $sessionId, bool $withSummary = false): array
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('cajas.operar');
        $session = CashBoxSession::findOrFail($sessionId);
        if ($session->user_id !== $user->id || $session->status !== 'ABIERTA' || $session->active_slot === null) {
            throw new AuthorizationException('Solo la titular puede preparar el cierre de su caja abierta.');
        }
        $session->load(['box:id,name,type', 'configuration:id,shifts,accounts,settlement_source,settlement_destination_key']);
        $shifts = [];
        foreach (['manana' => 'Mañana', 'tarde' => 'Tarde'] as $key => $label) {
            if (isset($session->configuration->shifts[$key])) {
                $shift = $session->configuration->shifts[$key];
                $shifts[$key] = ['label' => $label, 'start' => $shift['start'], 'end' => $shift['end'], 'liquidates' => (bool) ($shift['liquidates'] ?? false)];
            }
        }

        $expected = $session->current_balances ?? $session->opening_balances;
        $accounts = [];
        foreach ($expected as $code => $unused) {
            $accounts[$code] = $session->configuration->accounts[$code] ?? ['name' => $code];
        }
        if (isset($accounts['efectivo'])) {
            $accounts = ['efectivo' => $accounts['efectivo']] + $accounts;
        }

        return ['revision' => $session->revision, 'family' => $session->box->family(), 'operating_capital' => (int) ($session->operating_capital_cents ?? $session->base_capital_cents),
            'commission_total' => $withSummary ? (int) CashBoxOperation::where('cash_box_session_id', $session->id)->where('status', 'VIGENTE')->sum('fee_cents') : null,
            'settlement_destination_key' => $session->configuration->settlement_destination_key, 'settlement_source' => $session->configuration->settlement_source, 'accounts' => $accounts, 'expected_balances' => $expected, 'box_id' => $session->cash_box_id, 'box' => $session->box->name, 'shifts' => $shifts];
    }

    public function assertOperationsReady(User $actor, int $sessionId): void
    {
        $this->execute($actor, $sessionId);
        if (DB::table('cash_box_corrections')->where('cash_box_session_id', $sessionId)->whereIn('status', ['PENDIENTE', 'DEVUELTA'])->exists()) {
            throw ValidationException::withMessages(['readings' => 'Resuelve las solicitudes de corrección pendientes o devueltas antes de continuar al resumen.']);
        }
    }
}
