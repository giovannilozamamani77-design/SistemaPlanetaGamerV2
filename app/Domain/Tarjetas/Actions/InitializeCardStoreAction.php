<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InitializeCardStoreAction
{
    public function __construct(private CardInventoryAuthorization $authorization, private CardInventoryCommandRunner $commands) {}

    public function execute(User $actor, array $data): CardStore
    {
        $this->authorization->authorize($actor, 'initialize');
        $v = Validator::make($data, ['cash_amount' => ['required'], 'stocks' => ['present', 'array', 'max:200'], 'stocks.*.product_id' => ['required', 'integer', 'distinct'], 'stocks.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'], 'stocks.*.unit_cost' => ['nullable'], 'request_key' => ['required', 'uuid']])->validate();
        $cash = CardInventoryInput::money($v['cash_amount'], 'cash_amount', true);
        $rows = [];
        foreach ($v['stocks'] as $i => $row) {
            $rows[] = ['product_id' => (int) $row['product_id'], 'quantity' => (int) $row['quantity'], 'unit_cost_cents' => filled($row['unit_cost'] ?? null) ? CardInventoryInput::money($row['unit_cost'], 'stocks.'.$i.'.unit_cost') : null];
        }
        usort($rows, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
        $result = $this->commands->execute($actor, 'initialize', 'INITIALIZE_STORE', $v['request_key'], ['cash_cents' => $cash, 'stocks' => $rows], function (CardStore $store, User $user) use ($cash, $rows): array {
            if ($store->initialized_at) {
                throw ValidationException::withMessages(['cash_amount' => 'El conteo inicial ya está registrado. No puede repetirse ni reemplazarse.']);
            }
            $store->update(['cash_balance_cents' => $cash, 'initialized_at' => now(), 'initialized_by' => $user->id, 'revision' => $store->revision + 1]);
            CardInventoryMovement::create(['card_store_id' => $store->id, 'user_id' => $user->id, 'kind' => 'INITIAL_CASH', 'quantity' => 0, 'cash_delta_cents' => $cash, 'cash_before_cents' => 0, 'cash_after_cents' => $cash, 'reference' => 'Efectivo real del conteo inicial']);
            foreach ($rows as $i => $row) {
                $product = CardProduct::whereKey($row['product_id'])->where('is_active', true)->lockForUpdate()->first();
                if (! $product) {
                    throw ValidationException::withMessages(['stocks' => 'Selecciona productos activos del catálogo para el conteo inicial.']);
                }
                $stock = CardStock::where('card_store_id', $store->id)->where('card_product_id', $product->id)->lockForUpdate()->firstOrFail();
                $value = $row['quantity'] === 0 ? 0 : ($row['unit_cost_cents'] === null ? null : $row['quantity'] * $row['unit_cost_cents']);
                $stock->update(['quantity' => $row['quantity'], 'cost_value_cents' => $value, 'cost_revision' => $stock->cost_revision + 1]);
                CardInventoryMovement::create(['card_store_id' => $store->id, 'card_product_id' => $product->id, 'user_id' => $user->id, 'kind' => 'INITIAL_STOCK', 'cost_before_cents' => 0, 'cost_after_cents' => $value, 'quantity' => $row['quantity'], 'unit_cost_cents' => $row['unit_cost_cents'], 'total_cost_cents' => $row['unit_cost_cents'] === null ? null : $row['quantity'] * $row['unit_cost_cents'], 'cash_delta_cents' => 0, 'stock_before' => 0, 'stock_after' => $row['quantity'], 'cash_before_cents' => $cash, 'cash_after_cents' => $cash, 'product_snapshot' => $product->snapshot(), 'reference' => 'Tarjetas reales del conteo inicial']);
            }

            return ['store_id' => $store->id];
        });

        return CardStore::findOrFail($result['store_id']);
    }
}
