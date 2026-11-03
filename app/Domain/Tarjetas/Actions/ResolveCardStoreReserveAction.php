<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Calculators\CardAverageCostCalculator;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreReserve;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ResolveCardStoreReserveAction
{
    public function execute(User $actor, int $reserveId, array $data): CardInventoryMovement
    {
        app(CardInventoryAuthorization::class)->authorize($actor, 'initialize');
        $v = Validator::make($data, ['method' => ['required', 'in:INCORPORAR,DEVOLVER'], 'amount' => ['required'], 'reason' => ['required', 'string', 'max:2000'], 'request_key' => ['required', 'uuid']])->validate();
        if (! trim($v['reason'])) {
            throw ValidationException::withMessages(['reason' => 'Explica la resolución.']);
        }
        $payload = ['reserve_id' => $reserveId, 'method' => $v['method'], 'amount' => $v['amount'], 'reason' => trim($v['reason'])];
        $result = app(CardInventoryCommandRunner::class)->execute($actor, 'initialize', 'SURPLUS_RESOLVE', $v['request_key'], $payload, function (CardStore $store, User $user) use ($payload): array {
            $r = CardStoreReserve::where('card_store_id', $store->id)->whereKey($payload['reserve_id'])->lockForUpdate()->firstOrFail();
            $amount = $r->card_product_id ? filter_var($payload['amount'], FILTER_VALIDATE_INT) : CardInventoryInput::money($payload['amount'], 'amount');
            if ($amount === false || $amount < 1 || $amount > $r->held_amount || $r->released_at) {
                throw ValidationException::withMessages(['amount' => 'La cantidad debe ser positiva y no superar la reserva pendiente.']);
            }
            $before = $store->cash_balance_cents;
            $stockBefore = null;
            $stockAfter = null;
            $valueBefore = null;
            $valueAfter = null;
            if ($payload['method'] === 'INCORPORAR') {
                if ($r->card_product_id) {
                    $stock = CardStock::where('card_store_id', $store->id)->where('card_product_id', $r->card_product_id)->lockForUpdate()->firstOrFail();
                    $stockBefore = $stock->quantity;
                    $stockAfter = $stockBefore + $amount;
                    $valueBefore = $stock->cost_value_cents;
                    $valueAfter = app(CardAverageCostCalculator::class)->afterCount($stockBefore, $valueBefore, $stockAfter);
                    $stock->update(['quantity' => $stockAfter, 'cost_value_cents' => $valueAfter, 'cost_revision' => $stock->cost_revision + 1]);
                } else {
                    $store->cash_balance_cents += $amount;
                }
            }
            $movement = CardInventoryMovement::create(['card_store_id' => $store->id, 'card_product_id' => $r->card_product_id, 'user_id' => $user->id, 'kind' => 'SURPLUS_RESOLVE', 'quantity' => $r->card_product_id ? $amount : 0, 'cash_delta_cents' => $store->cash_balance_cents - $before, 'cost_before_cents' => $valueBefore, 'cost_after_cents' => $valueAfter, 'stock_before' => $stockBefore, 'stock_after' => $stockAfter, 'cash_before_cents' => $before, 'cash_after_cents' => $store->cash_balance_cents, 'reference' => 'Reserva #'.$r->id.' · '.$payload['method'].' · '.$payload['reason'], 'product_snapshot' => ['reserve_id' => $r->id, 'method' => $payload['method'], 'resolved_amount' => $amount, 'reason' => $payload['reason']]]);
            $r->held_amount -= $amount;
            if (! $r->held_amount) {
                $r->released_at = now();
            }$r->save();
            $store->revision++;
            $store->save();

            return ['movement_id' => $movement->id];
        });

        return CardInventoryMovement::findOrFail($result['movement_id']);
    }
}
