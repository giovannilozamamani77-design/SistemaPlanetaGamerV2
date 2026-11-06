<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Authorization\CashBoxCommand;
use App\Domain\Cajas\Calculators\CashBoxCommissionCalculator;
use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Services\CashBoxScheduleSafety;
use App\Domain\ControlHorario\Actions\CaptureCashBoxContinuityAction;
use App\Domain\ControlHorario\Actions\CaptureCashBoxEarlyWorkAction;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordCashBoxOperationAction
{
    public function execute(User $actor, int $sessionId, array $data): CashBoxOperation
    {
        $v = Validator::make($data, [
            'amount' => ['required', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'], 'source' => ['nullable', 'string'], 'destination' => ['nullable', 'string'],
            'fee_type' => ['required', Rule::in(['NINGUNA', 'FACTURAS', 'ACH'])], 'bill_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'many_bills_confirmed' => ['sometimes', 'boolean'], 'manual_fee' => ['nullable', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'],
            'fee_component' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:2000'], 'ach_external_confirmed' => ['sometimes', 'boolean'],
        ])->validate();
        $id = app(CashBoxCommand::class)->execute($actor, $sessionId, true, 'operation', $data, function (User $user, $session) use ($v): int {
            if ($session->status !== 'ABIERTA') {
                throw ValidationException::withMessages(['reason' => 'La sesión está congelada; no admite operaciones.']);
            }
            if ($v['fee_type'] === 'ACH' && CashBox::findOrFail($session->cash_box_id)->family() !== 'BCP') {
                throw ValidationException::withMessages(['fee_type' => 'Tigo Money conserva su tarifario propio; no se aplica ACH de BCP.']);
            }
            $balances = $session->current_balances ?? $session->opening_balances;
            $principal = Bolivianos::cents($v['amount']);
            $bills = (int) ($v['bill_count'] ?? 0);
            if ($v['fee_type'] === 'FACTURAS' && ($bills < 1 || ($bills > 5 && ! ($v['many_bills_confirmed'] ?? false)))) {
                throw ValidationException::withMessages(['bill_count' => 'Confirma la cantidad de facturas distintas; más de cinco exige comprobación.']);
            }
            if ($v['fee_type'] === 'ACH' && ($principal === 0 || ! ($v['ach_external_confirmed'] ?? false))) {
                throw ValidationException::withMessages(['fee_type' => 'ACH requiere importe y confirmar destino externo distinto de BCP/Yape.']);
            }
            foreach (array_filter([$v['source'] ?? null, $v['destination'] ?? null, $v['fee_component']]) as $component) {
                if (! array_key_exists($component, $balances)) {
                    throw ValidationException::withMessages(['source' => 'Selecciona componentes de esta caja.']);
                }
            }
            if ($principal > 0 && (! filled($v['source'] ?? null) || ! filled($v['destination'] ?? null) || $v['source'] === $v['destination'])) {
                throw ValidationException::withMessages(['destination' => 'Indica origen y destino distintos para el movimiento.']);
            }
            $suggested = app(CashBoxCommissionCalculator::class)->calculate($v['fee_type'], $principal, $bills);
            $manual = filled($v['manual_fee'] ?? null);
            $fee = $manual ? Bolivianos::cents($v['manual_fee']) : $suggested;
            if ($manual && mb_strlen(trim($v['reason'] ?? '')) < 3) {
                throw ValidationException::withMessages(['reason' => 'La comisión manual exige motivo.']);
            }
            if ($principal === 0 && $fee === 0) {
                throw ValidationException::withMessages(['amount' => 'No hay movimiento ni comisión para registrar.']);
            }
            $deltas = array_fill_keys(array_keys($balances), 0);
            if ($principal > 0) {
                $deltas[$v['source']] -= $principal;
                $deltas[$v['destination']] += $principal;
            }
            $deltas[$v['fee_component']] += $fee;
            $reserved = app(ReservedCashBoxFunds::class)->forBox($session->cash_box_id);
            foreach ($balances as $code => $balance) {
                $balances[$code] += $deltas[$code];
                if ($balances[$code] < (int) ($reserved[$code] ?? 0)) {
                    throw ValidationException::withMessages(['amount' => 'El movimiento supera el saldo esperado del componente.']);
                }
            }
            $operation = CashBoxOperation::create(['cash_box_session_id' => $session->id, 'user_id' => $user->id, 'source' => $v['source'] ?? null, 'destination' => $v['destination'] ?? null, 'amount_cents' => $principal,
                'fee_type' => $v['fee_type'], 'bill_count' => $bills, 'suggested_fee_cents' => $suggested, 'fee_cents' => $fee, 'fee_component' => $v['fee_component'], 'deltas' => $deltas, 'reason' => $v['reason'] ?? null, 'status' => 'VIGENTE', 'created_at' => now()]);
            $session->update(['current_balances' => $balances, 'revision' => $session->revision + 1]);

            return $operation->id;
        });

        $operation = CashBoxOperation::findOrFail($id);
        app(CashBoxScheduleSafety::class)->attempt(function () use ($operation): void {
            app(CaptureCashBoxEarlyWorkAction::class)->operation($operation, CashBoxSession::findOrFail($operation->cash_box_session_id));
            app(CaptureCashBoxContinuityAction::class)->fromOperation($operation);
            app(CaptureCashBoxEarlyWorkAction::class)->safelyForSession($operation->cash_box_session_id);
        });

        return $operation;
    }
}
