<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordCardCashDepositAction
{
    public function execute(User $actor, array $data): CardInventoryMovement
    {
        app(CardInventoryAuthorization::class)->authorize($actor, 'deposit');
        $v = Validator::make($data, ['account_id' => ['required', 'integer'], 'account_revision' => ['required', 'integer', 'min:1'], 'amount' => ['required'], 'operation_reference' => ['nullable', 'string', 'max:200'], 'request_key' => ['required', 'uuid']])->validate();
        $payload = ['account_id' => (int) $v['account_id'], 'account_revision' => (int) $v['account_revision'], 'amount_cents' => CardInventoryInput::money($v['amount'], 'amount'), 'operation_reference' => trim($v['operation_reference'] ?? '')];
        $result = app(CardInventoryCommandRunner::class)->execute($actor, 'deposit', 'CASH_ADMIN_DEPOSIT', $v['request_key'], $payload, function (CardStore $store, User $user) use ($payload): array {
            if (! $store->initialized_at) {
                throw ValidationException::withMessages(['amount' => 'Registra el inventario inicial antes de depositar.']);
            }
            if ($payload['amount_cents'] > $store->cash_balance_cents) {
                throw ValidationException::withMessages(['amount' => 'El importe supera el efectivo disponible de Tarjetas.']);
            }
            $account = CardDepositAccount::whereKey($payload['account_id'])->lockForUpdate()->first();
            if (! $account || ! $account->is_active || $account->revision !== $payload['account_revision']) {
                throw ValidationException::withMessages(['account_id' => 'La cuenta cambió o fue desactivada. Vuelve a seleccionarla antes de registrar.']);
            }
            $before = $store->cash_balance_cents;
            $after = $before - $payload['amount_cents'];
            $movement = CardInventoryMovement::create(['card_store_id' => $store->id, 'user_id' => $user->id, 'kind' => 'ADMIN_CASH_DEPOSIT', 'quantity' => 0, 'cash_delta_cents' => -$payload['amount_cents'], 'cash_before_cents' => $before, 'cash_after_cents' => $after, 'destination_snapshot' => $account->snapshot(), 'reference' => $payload['operation_reference'] ?: null]);
            $store->update(['cash_balance_cents' => $after, 'revision' => $store->revision + 1]);

            return ['movement_id' => $movement->id];
        });

        return CardInventoryMovement::findOrFail($result['movement_id']);
    }
}
