<?php

namespace App\Domain\Tarjetas\Services;

use App\Domain\Tarjetas\Calculators\CardAverageCostCalculator;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CardStockEntryRecorder
{
    public function execute(CardStore $store, User $user, CardProduct $product, string $kind, int $quantity, ?int $cost, string $reference): CardInventoryMovement
    {
        if (! $store->initialized_at) {
            throw ValidationException::withMessages(['product_id' => 'Administración debe registrar el conteo inicial y el efectivo real antes de registrar movimientos.']);
        }
        if (! $product->is_active) {
            throw ValidationException::withMessages(['product_id' => 'Este producto está inactivo. Selecciona uno disponible.']);
        }
        if (($kind === 'PURCHASE' && ! in_array($product->company, ['TIGO', 'VIVA', 'ENTEL'], true)) || ($kind === 'ADMIN_DELIVERY' && ! in_array($product->company, ['TIGO', 'VIVA', 'ENTEL'], true))) {
            throw ValidationException::withMessages(['product_id' => 'Las compras y entregas de Administración aceptan Entel, Tigo o Viva.']);
        }
        $total = $cost === null ? null : $quantity * $cost;
        $delta = $kind === 'PURCHASE' ? -$total : 0;
        if ($store->cash_balance_cents + $delta < 0) {
            throw ValidationException::withMessages(['unit_price' => 'El efectivo de Tarjetas no alcanza para esta compra. Revisa cantidad y precio.']);
        }
        $stock = CardStock::where('card_store_id', $store->id)->where('card_product_id', $product->id)->lockForUpdate()->firstOrFail();
        $before = $stock->quantity;
        $cash = $store->cash_balance_cents;
        $valueBefore = $stock->cost_value_cents;
        $valueAfter = app(CardAverageCostCalculator::class)->receive($before, $valueBefore, $quantity, $total);
        $stock->update(['quantity' => $before + $quantity, 'cost_value_cents' => $valueAfter, 'cost_revision' => $stock->cost_revision + 1]);
        $store->update(['cash_balance_cents' => $cash + $delta, 'revision' => $store->revision + 1]);

        return CardInventoryMovement::create(['card_store_id' => $store->id, 'card_product_id' => $product->id, 'user_id' => $user->id, 'kind' => $kind, 'quantity' => $quantity, 'cost_before_cents' => $valueBefore, 'cost_after_cents' => $valueAfter, 'unit_cost_cents' => $cost, 'total_cost_cents' => $total, 'cash_delta_cents' => $delta, 'stock_before' => $before, 'stock_after' => $stock->quantity, 'cash_before_cents' => $cash, 'cash_after_cents' => $store->cash_balance_cents, 'product_snapshot' => $product->snapshot(), 'reference' => $reference ?: null]);
    }
}
