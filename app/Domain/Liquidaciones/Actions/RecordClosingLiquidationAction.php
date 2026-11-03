<?php

namespace App\Domain\Liquidaciones\Actions;

use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxLiquidation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Liquidaciones\Queries\LiquidationAccountQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordClosingLiquidationAction
{
    /** Internal writer: the closing Action owns the transaction and all box/session locks. */
    public function execute(User $actor, CashBoxClose $close, array $calculation, array $data): void
    {
        $amount = $calculation['amount_cents'];
        if ($amount === 0) {
            if ((int) ($data['amount_cents'] ?? 0) !== 0) {
                throw ValidationException::withMessages(['closing' => 'El importe cambió. Vuelve a consultar la liquidación.']);
            }

            return;
        }
        $account = app(LiquidationAccountQuery::class)->forConfiguration($actor, CashBoxSession::findOrFail($close->cash_box_session_id)->configuration, true);
        if (! $account || $account->is_demo || (int) ($data['account_id'] ?? 0) !== $account->id) {
            throw ValidationException::withMessages(['closing' => 'Configura una cuenta real o vuelve a revisar el destino actualizado antes de confirmar.']);
        }
        if (! $calculation['can_liquidate'] || (int) ($data['amount_cents'] ?? -1) !== $amount || ! ($data['transferred'] ?? false) || trim($data['operation_reference'] ?? '') === '') {
            throw ValidationException::withMessages(['closing' => 'Verifica el importe, indica la referencia y confirma que realizaste la transferencia.']);
        }
        $reference = trim($data['operation_reference']);
        if (DB::table('cash_box_liquidations')->where('destination', $account->destination)->where('operation_reference', $reference)->exists()) {
            throw ValidationException::withMessages(['closing' => 'Esa referencia de transferencia ya fue registrada. Revisa el historial.']);
        }
        $record = CashBoxLiquidation::create(['cash_box_close_id' => $close->id, 'cash_box_id' => $close->cash_box_id, 'user_id' => $close->user_id,
            'theoretical_cents' => $amount, 'safe_cents' => $amount, 'amount_cents' => $amount, 'remaining_cents' => 0,
            'source' => $close->settlement_source, 'destination' => $account->destination, 'operation_reference' => $reference,
            'reason' => 'Transferencia declarada por la titular al confirmar el cierre.', 'created_at' => now()]);
        DB::table('cash_box_liquidation_allocations')->insert(['cash_box_liquidation_id' => $record->id, 'cash_box_close_id' => $close->id, 'amount_cents' => $amount]);
        $close->update(['commission_account_id' => $account->id, 'settlement_destination' => $account->destination, 'liquidated_cents' => $close->liquidated_cents + $amount,
            'real_balances' => $calculation['after_balances']]);
    }
}
