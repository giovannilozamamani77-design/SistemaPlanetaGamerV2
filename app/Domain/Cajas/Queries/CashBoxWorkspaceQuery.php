<?php

namespace App\Domain\Cajas\Queries;

use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxCorrection;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class CashBoxWorkspaceQuery
{
    public function execute(User $actor, int $accessId, int $operationsPage = 1): array
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('cajas.operar');
        $access = CashBoxAccess::findOrFail($accessId);
        if ($access->user_id !== $user->id) {
            throw new AuthorizationException;
        }
        $box = CashBox::findOrFail($access->cash_box_id);
        $session = $access->cash_box_session_id ? CashBoxSession::findOrFail($access->cash_box_session_id)
            : CashBoxSession::where('cash_box_id', $box->id)->whereNotNull('active_slot')->first();
        if ($session && ($session->user_id !== $user->id || $session->status !== 'ABIERTA')) {
            throw new AuthorizationException;
        }
        $config = CashBoxConfiguration::findOrFail($session?->configuration_id ?? $access->configuration_id);

        $operationsPage = max(1, min(1000000, $operationsPage));
        $operations = $session ? CashBoxOperation::where('cash_box_session_id', $session->id)->latest('id')->offset(($operationsPage - 1) * 5)->limit(6)
            ->select(['id', 'amount_cents', 'fee_type', 'fee_cents', 'source', 'destination', 'status', 'created_at', 'commission_only', 'movement'])
            ->addSelect(['correction_status' => CashBoxCorrection::select('status')->whereColumn('cash_box_operation_id', 'cash_box_operations.id')->limit(1)])
            ->get()->map(function (CashBoxOperation $operation) use ($config): array {
                return [...$operation->toArray(), 'label' => match ($operation->fee_type) {
                    'ACH' => 'ACH / otros bancos','FACTURAS' => 'Pago de servicios','INTERNACIONAL' => 'Margen Brasil/Pix',default => 'Movimiento'
                },
                    'source_label' => $config->accounts[$operation->source]['name'] ?? null, 'destination_label' => $config->accounts[$operation->destination]['name'] ?? null,
                    'time' => $operation->created_at->setTimezone('America/La_Paz')->format('H:i')];
            })->all() : [];
        $hasMoreOperations = count($operations) > 5;
        $operations = array_slice($operations, 0, 5);
        $alerts = $session ? app(CashBoxCorrectionAlertsQuery::class)->forSession($session->id) : ['unread' => 0, 'returned' => 0];
        $feedback = $session ? CashBoxCorrection::where('cash_box_session_id', $session->id)->whereNotNull('decided_at')
            ->with('operation:id,status,fee_type,fee_cents')->orderByRaw("CASE WHEN status = 'DEVUELTA' THEN 0 WHEN response_version > viewed_response_version THEN 1 ELSE 2 END")->orderByDesc('decided_at')->orderByDesc('id')->limit(5)
            ->get(['id', 'cash_box_operation_id', 'status', 'reason', 'decision_reason', 'decided_at', 'response_version', 'viewed_response_version'])->map(fn (CashBoxCorrection $request): array => [
                'id' => $request->cash_box_operation_id, 'request_id' => $request->id, 'is_unread' => $request->response_version > $request->viewed_response_version, 'status' => $request->operation->status,
                'fee_type' => $request->operation->fee_type, 'fee_cents' => $request->operation->fee_cents,
                'label' => 'Operación #'.$request->cash_box_operation_id, 'correction_status' => $request->status,
                'correction_reason' => $request->reason, 'decision_reason' => $request->decision_reason,
                'decided_at' => $request->decided_at->setTimezone('America/La_Paz')->format('d/m/Y H:i'),
            ])->all() : [];
        $minutes = $session ? max(0, (int) $session->opened_at->diffInMinutes(now())) : 0;
        $ownBoxes = $session ? CashBox::query()->join('cash_box_sessions as active_session', 'active_session.cash_box_id', '=', 'cash_boxes.id')
            ->where('active_session.user_id', $user->id)->where('active_session.status', 'ABIERTA')->whereNotNull('active_session.active_slot')
            ->orderBy('cash_boxes.name')->get(['cash_boxes.id', 'cash_boxes.code', 'cash_boxes.name', 'cash_boxes.type', 'active_session.opened_at as session_opened_at']) : collect();

        $fees = $session ? (int) CashBoxOperation::where('cash_box_session_id', $session->id)->where('status', 'VIGENTE')->sum('fee_cents') : 0;
        $openingSurplus = (int) ($session?->comparison['opening_surplus_cents'] ?? 0);

        $requiredOpeningTotal = CashBoxSession::where('cash_box_id', $box->id)->exists()
            ? array_sum($access->expected_balances)
            : app(CashBoxOperatingCapital::class)->amount($box->id, $config->base_capital_cents) + array_sum(app(ReservedCashBoxFunds::class)->forBox($box->id));

        return ['opening_required_total' => $requiredOpeningTotal, 'bcp_account_reference' => $box->family() === 'BCP' ? (trim((string) ($config->accounts['bcp']['reference'] ?? '')) ?: null) : null, 'reserved_funds' => app(ReservedCashBoxFunds::class)->forBox($box->id), 'box_id' => $box->id, 'cashier' => $user->name, 'opening_total' => $session ? (int) array_sum($session->opening_balances) : null, 'expected_total' => $session ? (int) array_sum($session->current_balances ?? $session->opening_balances) : null, 'commission_total' => $fees, 'opening_surplus' => $openingSurplus, 'commission_and_surplus_total' => $fees + $openingSurplus, 'receipt_classification' => $session?->comparison['receipt_classification'] ?? null, 'elapsed_seconds' => $session ? max(0, now()->getTimestamp() - $session->opened_at->getTimestamp()) : 0, 'elapsed_label' => intdiv($minutes, 60).' h '.($minutes % 60).' min', 'own_boxes' => $ownBoxes, 'opening_count_details' => $session?->comparison['count_details'] ?? null, 'opening_balances' => $session?->opening_balances, 'box' => $box->name, 'type' => $box->family(), 'accounts' => $config->accounts,
            'base_capital' => (int) $config->base_capital_cents, 'operating_capital' => $session ? (int) ($session->operating_capital_cents ?? $session->base_capital_cents) : app(CashBoxOperatingCapital::class)->amount($box->id, $config->base_capital_cents), 'temporary_funds' => app(CashBoxOperatingCapital::class)->funds($box->id), 'first_count' => ! $session && $config->first_count_pending && ! CashBoxSession::where('cash_box_id', $box->id)->exists(),
            'expected' => $session?->expected_balances ?? $access->expected_balances, 'session_id' => $session?->id,
            'balances' => $session ? ($session->current_balances ?? $session->opening_balances) : null,
            'revision' => $session?->revision ?? 0,
            'session_timing' => app(CashBoxSessionTimingQuery::class)->forSession($session),
            'operations' => $operations, 'operations_page' => ['page' => $operationsPage, 'has_next' => $hasMoreOperations, 'has_previous' => $operationsPage > 1], 'correction_feedback' => $feedback, 'correction_alerts' => $alerts,
            'pending_corrections' => $session ? CashBoxCorrection::where('cash_box_session_id', $session->id)->whereIn('status', ['PENDIENTE', 'DEVUELTA'])->count() : 0, 'opened_at' => $session?->opened_at->setTimezone('America/La_Paz')->format('d/m/Y H:i'),
            'has_observation' => $session ? $session->comparison['difference_cents'] !== 0 : false];
    }
}
