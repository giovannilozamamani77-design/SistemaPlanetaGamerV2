<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardInventoryCommand;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Models\EntelDigitalMovement;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ValueRemainingCardCostAction
{
    public function execute(User $actor, array $data): array
    {
        $type = $data['type'] ?? '';
        $this->authorize($actor->fresh(), $type);
        $v = Validator::make($data, [
            'type' => ['required', Rule::in(['PHYSICAL', 'DIGITAL'])], 'id' => ['required', 'integer', 'min:1'],
            'expected_quantity' => ['required', 'integer', 'min:1'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'total_cost' => ['required'], 'reason' => ['required', 'string', 'max:160'], 'request_key' => ['required', 'uuid'],
        ])->validate();
        $payload = ['type' => $v['type'], 'id' => (int) $v['id'], 'expected_quantity' => (int) $v['expected_quantity'], 'expected_revision' => (int) $v['expected_revision'], 'cost_cents' => CardInventoryInput::money($v['total_cost'], 'total_cost', true), 'reason' => trim($v['reason'])];
        if ($payload['reason'] === '') {
            throw ValidationException::withMessages(['reason' => 'Indica el respaldo del costo real.']);
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $payload, $hash): array {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $payload['type']);
            if ($old = CardInventoryCommand::where('request_key', $v['request_key'])->first()) {
                if ($old->user_id !== $user->id) {
                    throw new AuthorizationException;
                }
                if ($old->action !== 'COST_VALUATION' || ! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_key' => 'Envío ya utilizado con otros datos.']);
                }

                return $old->result;
            }
            $physical = $payload['type'] === 'PHYSICAL';
            $store = $physical ? CardStore::where('code', 'TIENDA')->lockForUpdate()->firstOrFail() : null;
            $record = $physical
                ? CardStock::where('card_store_id', $store->id)->whereKey($payload['id'])->lockForUpdate()->firstOrFail()
                : EntelDigitalAccount::whereKey($payload['id'])->lockForUpdate()->firstOrFail();
            $quantity = $physical ? $record->quantity : $record->balance_cents;
            if ($quantity !== $payload['expected_quantity'] || $record->cost_revision !== $payload['expected_revision']) {
                throw ValidationException::withMessages(['total_cost' => 'Las existencias cambiaron. Actualiza y revisa el costo de lo que queda.']);
            }
            if ($record->cost_value_cents !== null) {
                throw ValidationException::withMessages(['total_cost' => 'Estas existencias ya tienen costo conocido. No se reescriben valoraciones.']);
            }
            $cost = $payload['cost_cents'];
            if ($physical) {
                $movement = CardInventoryMovement::create(['card_store_id' => $store->id, 'card_product_id' => $record->card_product_id, 'user_id' => $user->id, 'kind' => 'COST_VALUATION', 'quantity' => 0, 'cost_before_cents' => null, 'cost_after_cents' => $cost, 'stock_before' => $quantity, 'stock_after' => $quantity, 'cash_delta_cents' => 0, 'cash_before_cents' => $store->cash_balance_cents, 'cash_after_cents' => $store->cash_balance_cents, 'product_snapshot' => $record->product->snapshot(), 'reference' => $payload['reason']]);
            } else {
                if (EntelDigitalMovement::where('request_key', $v['request_key'])->exists()) {
                    throw ValidationException::withMessages(['request_key' => 'Envío digital ya utilizado.']);
                }
                $movement = EntelDigitalMovement::create(['entel_digital_account_id' => $record->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'payload' => $payload, 'operation' => 'COST_SET', 'credited_cents' => 0, 'debited_cents' => 0, 'balance_before_cents' => $quantity, 'balance_after_cents' => $quantity, 'cost_before_cents' => null, 'cost_after_cents' => $cost, 'reference' => $payload['reason'], 'created_at' => now()]);
            }
            $record->update(['cost_value_cents' => $cost, 'cost_revision' => $record->cost_revision + 1]);
            $result = ['type' => $payload['type'], 'movement_id' => $movement->id, 'cost_cents' => $cost];
            CardInventoryCommand::create(['user_id' => $user->id, 'request_key' => $v['request_key'], 'action' => 'COST_VALUATION', 'payload_hash' => $hash, 'payload' => $payload, 'result' => $result, 'created_at' => now()]);

            return $result;
        }, 3);
    }

    private function authorize(User $user, string $type): void
    {
        if ($type === 'DIGITAL') {
            Gate::forUser($user)->authorize('record', EntelDigitalAccount::class);
        } else {
            Gate::forUser($user)->authorize('initialize', CardProduct::class);
        }
    }
}
