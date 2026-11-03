<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Calculators\CardCashFundingCalculator;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordCardCashFundingAction
{
    public function execute(User $actor, array $data): CardInventoryMovement
    {
        app(CardInventoryAuthorization::class)->authorize($actor, 'cashFunding');
        $v = Validator::make($data, ['amount' => ['required'], 'reference' => ['nullable', 'string', 'max:200'], 'request_key' => ['required', 'uuid']])->validate();
        $payload = ['amount_cents' => CardInventoryInput::money($v['amount'], 'amount'), 'reference' => trim($v['reference'] ?? '')];
        $result = app(CardInventoryCommandRunner::class)->execute($actor, 'cashFunding', 'CASH_ADMIN_FUNDING', $v['request_key'], $payload, function (CardStore $store, User $user) use ($payload, $v): array {
            if (! $store->initialized_at) {
                throw ValidationException::withMessages(['amount' => 'Registra el inventario inicial antes de ingresar efectivo.']);
            }
            $before = $store->cash_balance_cents;
            $after = app(CardCashFundingCalculator::class)->after($before, $v['amount']);
            $movement = CardInventoryMovement::create(['card_store_id' => $store->id, 'user_id' => $user->id, 'kind' => 'ADMIN_CASH_IN', 'quantity' => 0, 'cash_delta_cents' => $payload['amount_cents'], 'cash_before_cents' => $before, 'cash_after_cents' => $after, 'reference' => $payload['reference'] ?: null]);
            $store->update(['cash_balance_cents' => $after, 'revision' => $store->revision + 1]);

            return ['movement_id' => $movement->id];
        });

        return CardInventoryMovement::findOrFail($result['movement_id']);
    }
}
