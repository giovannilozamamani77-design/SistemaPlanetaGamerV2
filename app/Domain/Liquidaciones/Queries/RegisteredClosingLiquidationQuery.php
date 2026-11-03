<?php

namespace App\Domain\Liquidaciones\Queries;

use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Liquidaciones\Calculators\LiquidationPreviewCalculator;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RegisteredClosingLiquidationQuery
{
    public function execute(User $actor, int $closeId): array
    {
        app(AdministrativeReview::class)->authorize($actor, 'cajas.liberar');
        $close = CashBoxClose::findOrFail($closeId);
        $session = CashBoxSession::findOrFail($close->cash_box_session_id);
        if ($close->workflow_version !== 3 || $close->cancelled_at || $close->released_at || ! $close->requires_liquidation || $session->status !== 'CIERRE_REGISTRADO') {
            throw ValidationException::withMessages(['closing' => 'Solo se liquidan cierres registrados pendientes.']);
        }
        $calculation = app(LiquidationPreviewCalculator::class)->calculate($close->real_balances, $close->capital_cents, $close->settlement_source, $close->reserved_funds_snapshot ?? []);

        return ['calculation' => $calculation, 'source' => $close->settlement_source,
            'account' => app(LiquidationAccountQuery::class)->forConfiguration($actor, $session->configuration),
            'revision' => $session->revision];
    }
}
