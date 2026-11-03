<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Authorization\CashBoxCommand;
use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordCashBoxTemporaryFundAction
{
    public function execute(User $actor, int $sessionId, array $data): int
    {
        $v = Validator::make($data, ['kind' => ['required', Rule::in(['DEPOSITO', 'RETIRO', 'DEVOLUCION'])], 'component' => ['required', 'string'], 'amount' => ['required', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'], 'reason' => ['required', 'string', 'min:3', 'max:2000'], 'fund_id' => ['nullable', 'integer'], 'occurred' => ['accepted']])->validate();
        $admin = User::findOrFail($actor->id)->hasRole('administrador');

        return app(CashBoxCommand::class)->execute($actor, $sessionId, ! $admin, 'temporary_fund', $data, function (User $user, $session) use ($v, $admin): int {
            if ($session->status !== 'ABIERTA') {
                throw ValidationException::withMessages(['reason' => 'Solo se registran fondos con la caja abierta.']);
            }
            $amount = Bolivianos::cents($v['amount']);
            $balances = $session->current_balances ?? $session->opening_balances;
            if ($amount <= 0 || ! array_key_exists($v['component'], $balances)) {
                throw ValidationException::withMessages(['amount' => 'Indica un importe positivo y un componente de esta caja.']);
            }
            $fund = null;
            if ($v['kind'] === 'DEVOLUCION') {
                if (! $admin) {
                    throw new AuthorizationException;
                }
                $fund = DB::table('cash_box_temporary_funds')->where('cash_box_id', $session->cash_box_id)->where('id', $v['fund_id'] ?? 0)->lockForUpdate()->first();
                if (! $fund || $amount > $fund->remaining_cents) {
                    throw ValidationException::withMessages(['amount' => 'La devolución supera el fondo pendiente.']);
                }
                $delta = $fund->kind === 'DEPOSITO' ? -$amount : $amount;
            } else {
                $delta = $v['kind'] === 'DEPOSITO' ? $amount : -$amount;
            }
            $reserved = app(ReservedCashBoxFunds::class)->forBox($session->cash_box_id);
            $capital = ($session->operating_capital_cents ?? $session->base_capital_cents) + $delta;
            if ($capital < 0 || (int) ($reserved[$v['component']] ?? 0) > $balances[$v['component']] + $delta) {
                throw ValidationException::withMessages(['amount' => 'El movimiento supera el saldo disponible o el capital operativo.']);
            }
            if ($fund) {
                $id = $fund->id;
                DB::table('cash_box_temporary_funds')->where('id', $id)->update(['remaining_cents' => $fund->remaining_cents - $amount]);
            } else {
                $id = DB::table('cash_box_temporary_funds')->insertGetId(['cash_box_id' => $session->cash_box_id, 'created_in_session_id' => $session->id, 'created_by' => $user->id, 'kind' => $v['kind'], 'component' => $v['component'], 'amount_cents' => $amount, 'remaining_cents' => $amount, 'reason' => $v['reason'], 'created_at' => now()]);
            }
            DB::table('cash_box_temporary_fund_movements')->insert(['cash_box_temporary_fund_id' => $id, 'cash_box_session_id' => $session->id, 'actor_user_id' => $user->id, 'component' => $v['component'], 'delta_cents' => $delta, 'reason' => $v['reason'], 'created_at' => now()]);
            $balances[$v['component']] += $delta;
            $session->update(['current_balances' => $balances, 'operating_capital_cents' => $capital, 'revision' => $session->revision + 1]);

            return $id;
        });
    }
}
