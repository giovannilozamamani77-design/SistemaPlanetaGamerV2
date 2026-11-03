<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Authorization\CashBoxCommand;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RestoreRegisteredCashBoxCapitalAction
{
    public function execute(User $actor, int $closeId, array $data): CashBoxClose
    {
        Validator::make($data, ['component' => ['required', 'string'], 'amount' => ['required', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'],
            'receipt' => ['required', 'string', 'min:3', 'max:2000'], 'occurred' => ['accepted'], 'expected_revision' => ['required', 'integer', 'min:0']])->validate();
        $close = CashBoxClose::findOrFail($closeId);
        app(CashBoxCommand::class)->execute($actor, $close->cash_box_session_id, false, 'restore_closing_v3', $data, function (User $admin, $session) use ($closeId, $data): int {
            $close = CashBoxClose::whereKey($closeId)->lockForUpdate()->firstOrFail();
            $amount = Bolivianos::cents($data['amount']);
            $deficit = max(0, $close->capital_cents - $close->operatingTotal());
            $alreadyRestored = (int) DB::table('cash_box_capital_restorations')->where('cash_box_close_id', $close->id)->sum('amount_cents');
            $remainingShortage = max(0, -$close->difference_cents - $alreadyRestored);
            $maximum = max($deficit, $remainingShortage);
            if ($close->workflow_version !== 3 || $close->cancelled_at || $close->released_at || $session->status !== 'CIERRE_REGISTRADO' || $session->revision !== (int) $data['expected_revision'] || $amount <= 0 || $amount > $maximum || ! array_key_exists($data['component'], $close->real_balances)) {
                throw ValidationException::withMessages(['receipt' => 'Revisa el cierre y el importe: solo se repone el faltante pendiente o el capital descubierto de un cierre vigente.']);
            }
            $balances = $close->real_balances;
            $balances[$data['component']] += $amount;
            $id = DB::table('cash_box_capital_restorations')->insertGetId(['cash_box_close_id' => $close->id, 'actor_user_id' => $admin->id,
                'kind' => 'APORTE_ADMINISTRATIVO', 'component' => $data['component'], 'amount_cents' => $amount, 'receipt' => $data['receipt'], 'created_at' => now()]);
            $ownTotal = array_sum($balances) - array_sum($close->reserved_funds_snapshot ?? []);
            $close->update(['real_balances' => $balances, 'liquidation_completed' => ! $close->requires_liquidation || $ownTotal === $close->capital_cents]);
            $session->update(['current_balances' => $balances, 'revision' => $session->revision + 1]);
            CashBoxAudit::create(['cash_box_id' => $session->cash_box_id, 'actor_user_id' => $admin->id, 'request_key' => $data['request_key'], 'action' => 'CAPITAL_REPUESTO',
                'payload_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 'before' => ['capital_deficit_cents' => $deficit],
                'after' => ['close_id' => $close->id, 'restoration_id' => $id, 'amount_cents' => $amount, 'balances' => $balances], 'reason' => $data['receipt'], 'created_at' => now()]);

            return $id;
        });

        return $close->fresh();
    }
}
