<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Authorization\CashBoxCommand;
use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use App\Domain\Cajas\Models\CashBoxCorrection;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DecideCashBoxCorrectionAction
{
    public function execute(User $actor, CashBoxCorrection $target, #[\SensitiveParameter] array $data): CashBoxCorrection
    {
        $target = $target->fresh();
        $v = Validator::make($data, ['decision' => ['required', Rule::in(['APROBADA', 'RECHAZADA', 'DEVUELTA'])], 'reason' => ['required', 'string', 'min:3', 'max:2000', 'regex:/\S/u'], 'pin' => ['required', 'string']])->validate();
        $id = app(CashBoxCommand::class)->execute($actor, $target->cash_box_session_id, false, 'correction_decision:'.$target->id, $data, function (User $user, $session) use ($target, $v): int {
            $request = CashBoxCorrection::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== 'ABIERTA' || ! in_array($request->status, ['PENDIENTE', 'DEVUELTA'], true)) {
                throw ValidationException::withMessages(['reason' => 'Esta solicitud ya está resuelta.']);
            }
            if ($v['decision'] === 'APROBADA') {
                $op = CashBoxOperation::whereKey($request->cash_box_operation_id)->lockForUpdate()->firstOrFail();
                $balances = $session->current_balances ?? $session->opening_balances;
                $reserved = app(ReservedCashBoxFunds::class)->forBox($session->cash_box_id);
                foreach ($op->deltas as $code => $delta) {
                    $balances[$code] -= $delta;
                    if ($balances[$code] < (int) ($reserved[$code] ?? 0)) {
                        throw ValidationException::withMessages(['reason' => 'La corrección produce un saldo imposible. Revisa los movimientos anteriores.']);
                    }
                }
                $op->update(['status' => 'ANULADA']);
                $session->update(['current_balances' => $balances, 'revision' => $session->revision + 1]);
            }
            $request->appendHistory($user, 'DECISION', $v['decision'], $v['reason']);
            $request->update(['response_version' => $request->response_version + 1, 'status' => $v['decision'], 'decision_reason' => $v['reason'], 'decided_by' => $user->id, 'decided_at' => now()]);

            return $request->id;
        });

        return CashBoxCorrection::findOrFail($id);
    }
}
