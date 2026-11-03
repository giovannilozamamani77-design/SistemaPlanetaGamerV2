<?php

namespace App\Domain\Prestamos\Actions;

use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Services\LoanCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageLoanFundAction
{
    public function execute(User $actor, array $data): LoanMovement
    {
        app(LoanAuthorization::class)->authorize($actor, 'manageFund');
        $v = Validator::make($data, ['kind' => ['required', Rule::in(['INITIAL', 'IN', 'OUT'])], 'amount' => ['required'], 'confirmed' => ['required', 'accepted'], 'reference' => ['nullable', 'string', 'max:200'], 'request_key' => ['required', 'uuid']])->validate();
        $amount = LoanInput::money($v['amount'], 'amount', $v['kind'] === 'INITIAL');
        $result = app(LoanCommandRunner::class)->execute($actor, 'manageFund', 'FUND', $v['request_key'], ['kind' => $v['kind'], 'amount' => $amount, 'reference' => trim($v['reference'] ?? '')], function ($fund, $user) use ($v, $amount): array {
            if (($v['kind'] === 'INITIAL') === (bool) $fund->initialized_at) {
                throw ValidationException::withMessages(['amount' => $fund->initialized_at ? 'El saldo inicial ya fue registrado. Usa un aporte o retiro real.' : 'Registra primero el saldo inicial real de Yape Préstamos.']);
            }
            $delta = $v['kind'] === 'OUT' ? -$amount : $amount;
            $after = $fund->balance_cents + $delta;
            if ($after < 0 || $after > LoanInput::MAX_CENTS) {
                throw ValidationException::withMessages(['amount' => 'El movimiento no puede dejar saldo negativo ni superar el límite del fondo.']);
            }
            $movement = LoanMovement::create(['user_id' => $user->id, 'kind' => $v['kind'], 'delta_cents' => $delta, 'before_cents' => $fund->balance_cents, 'after_cents' => $after, 'reference' => trim($v['reference'] ?? '') ?: null, 'created_at' => now()]);
            $fund->update(['balance_cents' => $after, 'revision' => $fund->revision + 1] + ($v['kind'] === 'INITIAL' ? ['initialized_at' => now(), 'initialized_by' => $user->id] : []));

            return ['id' => $movement->id];
        });

        return LoanMovement::findOrFail($result['id']);
    }
}
