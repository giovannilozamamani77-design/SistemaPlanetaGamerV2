<?php

namespace App\Domain\Tarjetas\Services;

use App\Domain\Tarjetas\Calculators\CardAverageCostCalculator;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreReserve;
use App\Models\User;

class CardStoreReserveService
{
    public function releaseDue(CardStore $store, User $actor): void
    {
        if (! $store->session_control_started_at) {
            return;
        }
        foreach (CardStoreReserve::where('card_store_id', $store->id)->whereNull('released_at')->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('id')->lockForUpdate()->get() as $reserve) {
            $before = $store->cash_balance_cents;
            $stockBefore = null;
            $stockAfter = null;
            $valueBefore = null;
            $valueAfter = null;
            if ($reserve->card_product_id) {
                $stock = CardStock::where('card_store_id', $store->id)->where('card_product_id', $reserve->card_product_id)->lockForUpdate()->firstOrFail();
                $stockBefore = $stock->quantity;
                $stockAfter = $stockBefore + $reserve->held_amount;
                $valueBefore = $stock->cost_value_cents;
                $valueAfter = app(CardAverageCostCalculator::class)->afterCount($stockBefore, $valueBefore, $stockAfter);
                $stock->update(['quantity' => $stockAfter, 'cost_value_cents' => $valueAfter, 'cost_revision' => $stock->cost_revision + 1]);
            } else {
                $store->cash_balance_cents += $reserve->held_amount;
            }
            CardInventoryMovement::create(['card_store_id' => $store->id, 'card_product_id' => $reserve->card_product_id, 'user_id' => $actor->id, 'kind' => $reserve->card_product_id ? 'SURPLUS_RELEASE_STOCK' : 'SURPLUS_RELEASE_CASH', 'quantity' => $reserve->card_product_id ? $reserve->held_amount : 0, 'cash_delta_cents' => $store->cash_balance_cents - $before, 'cost_before_cents' => $valueBefore, 'cost_after_cents' => $valueAfter, 'stock_before' => $stockBefore, 'stock_after' => $stockAfter, 'cash_before_cents' => $before, 'cash_after_cents' => $store->cash_balance_cents, 'reference' => 'Vencimiento reserva #'.$reserve->id]);
            $reserve->update(['held_amount' => 0, 'released_at' => now()]);
            $store->revision++;
            $store->save();
        }
    }

    public function retained(CardStore $store, ?int $productId, int $counted): int
    {
        $remaining = $counted;
        $held = 0;
        foreach (CardStoreReserve::where('card_store_id', $store->id)->where('card_product_id', $productId)->whereNull('released_at')->orderBy('id')->lockForUpdate()->get() as $r) {
            $actual = min($remaining, $r->held_amount);
            $missing = $r->held_amount - $actual;
            if ($missing) {
                $r->update(['held_amount' => $actual, 'missing_amount' => $r->missing_amount + $missing]);
            }$remaining -= $actual;
            $held += $actual;
        }

        return $held;
    }
}
