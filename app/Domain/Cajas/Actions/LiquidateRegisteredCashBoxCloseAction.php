<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Authorization\CashBoxCommand;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Liquidaciones\Actions\RecordClosingLiquidationAction;
use App\Domain\Liquidaciones\Calculators\LiquidationPreviewCalculator;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LiquidateRegisteredCashBoxCloseAction
{
    public function execute(User $actor, int $closeId, array $data): CashBoxClose
    {
        Validator::make($data, ['expected_revision' => ['required', 'integer'], 'amount_cents' => ['required', 'integer', 'min:0'],
            'account_id' => ['nullable', 'integer'], 'operation_reference' => ['nullable', 'string', 'max:120'], 'transferred' => ['nullable', 'boolean']])->validate();
        $data['operation_reference'] = trim($data['operation_reference'] ?? '');
        $close = CashBoxClose::findOrFail($closeId);
        app(CashBoxCommand::class)->execute($actor, $close->cash_box_session_id, false, 'settle_restored_closing', $data, function (User $admin, $session) use ($closeId, $data): int {
            $close = CashBoxClose::whereKey($closeId)->lockForUpdate()->firstOrFail();
            if ($close->workflow_version !== 3 || $close->cancelled_at || $close->released_at || ! $close->requires_liquidation || $session->status !== 'CIERRE_REGISTRADO' || $session->revision !== (int) $data['expected_revision']) {
                throw ValidationException::withMessages(['closing' => 'Revisa el cierre pendiente y su revisión antes de liquidar.']);
            }
            $calculation = app(LiquidationPreviewCalculator::class)->calculate($close->real_balances, $close->capital_cents, $close->settlement_source, $close->reserved_funds_snapshot ?? []);
            if ($calculation['shortage_cents'] > 0) {
                throw ValidationException::withMessages(['closing' => 'Primero debe ingresar la reposición real del capital.']);
            }
            app(RecordClosingLiquidationAction::class)->execute($admin, $close, $calculation, $data);
            $close->update(['liquidation_completed' => true]);
            $session->update(['current_balances' => $close->real_balances, 'revision' => $session->revision + 1]);
            CashBoxAudit::create(['cash_box_id' => $session->cash_box_id, 'actor_user_id' => $admin->id, 'request_key' => $data['request_key'],
                'action' => 'LIQUIDACION_TRAS_REPOSICION', 'payload_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                'before' => ['close_id' => $close->id], 'after' => ['amount_cents' => $calculation['amount_cents']],
                'reason' => 'Liquidación real tras reposición; incidencia original conservada.', 'created_at' => now()]);

            return $close->id;
        });

        return $close->fresh();
    }
}
