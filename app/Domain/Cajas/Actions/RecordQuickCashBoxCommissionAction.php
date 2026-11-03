<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Authorization\CashBoxCommand;
use App\Domain\Cajas\Calculators\CashBoxCommissionCalculator;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\ControlHorario\Actions\CaptureCashBoxContinuityAction;
use App\Domain\ControlHorario\Actions\CaptureCashBoxEarlyWorkAction;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordQuickCashBoxCommissionAction
{
    public function execute(User $actor, int $sessionId, array $data): CashBoxOperation
    {
        $v = Validator::make($data, [
            'fee_type' => ['required', Rule::in(['ACH', 'FACTURAS'])],
            'amount' => ['required_if:fee_type,ACH', 'nullable', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'],
            'movement' => ['required_if:fee_type,ACH', 'nullable', Rule::in(['DEPOSITO', 'RETIRO'])],
            'bill_count' => ['required_if:fee_type,FACTURAS', 'nullable', 'integer', 'min:1', 'max:10000'],
            'manual_fee' => ['nullable', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'],
        ])->validate();
        $id = app(CashBoxCommand::class)->execute($actor, $sessionId, true, 'quick_commission', $data, function (User $user, $session) use ($v): int {
            if ($session->status !== 'ABIERTA') {
                throw ValidationException::withMessages(['amount' => 'La caja debe estar abierta.']);
            }
            if ($v['fee_type'] === 'ACH' && CashBox::findOrFail($session->cash_box_id)->family() !== 'BCP') {
                throw ValidationException::withMessages(['amount' => 'ACH solo está disponible para Agente BCP.']);
            }
            $balances = $session->current_balances ?? $session->opening_balances;
            if (! array_key_exists('efectivo', $balances)) {
                throw ValidationException::withMessages(['manual_fee' => 'Configura el saldo de efectivo antes de registrar comisiones.']);
            }
            $principal = $v['fee_type'] === 'ACH' ? Bolivianos::cents($v['amount']) : 0;
            if ($v['fee_type'] === 'ACH' && $principal === 0) {
                throw ValidationException::withMessages(['amount' => 'Ingresa un monto mayor a cero.']);
            }
            $bills = $v['fee_type'] === 'FACTURAS' ? (int) $v['bill_count'] : 0;
            $suggested = app(CashBoxCommissionCalculator::class)->calculate($v['fee_type'], $principal, $bills);
            $fee = filled($v['manual_fee'] ?? null) ? Bolivianos::cents($v['manual_fee']) : $suggested;
            $deltas = array_fill_keys(array_keys($balances), 0);
            $deltas['efectivo'] = $fee;
            $balances['efectivo'] += $fee;
            $operation = CashBoxOperation::create([
                'cash_box_session_id' => $session->id, 'user_id' => $user->id,
                'source' => null, 'destination' => null, 'amount_cents' => $principal,
                'fee_type' => $v['fee_type'], 'bill_count' => $bills,
                'suggested_fee_cents' => $suggested, 'fee_cents' => $fee, 'fee_component' => 'efectivo',
                'deltas' => $deltas, 'reason' => null, 'status' => 'VIGENTE', 'created_at' => now(),
                'commission_only' => true, 'movement' => $v['fee_type'] === 'ACH' ? $v['movement'] : null,
            ]);
            $session->update(['current_balances' => $balances, 'revision' => $session->revision + 1]);
            app(CaptureCashBoxEarlyWorkAction::class)->operation($operation, $session);

            return $operation->id;
        });

        $operation = CashBoxOperation::findOrFail($id);
        app(CaptureCashBoxContinuityAction::class)->fromOperation($operation);
        app(CaptureCashBoxEarlyWorkAction::class)->safelyForSession($operation->cash_box_session_id);

        return $operation;
    }
}
