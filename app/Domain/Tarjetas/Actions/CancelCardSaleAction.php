<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Calculators\CardAverageCostCalculator;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardSaleCancellation;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CancelCardSaleAction
{
    public function available(User $actor, int $saleId): bool
    {
        if (! Gate::forUser($actor->fresh())->allows('cancelSale', CardProduct::class)) {
            return false;
        }
        $sale = CardSale::find($saleId);
        if (! $sale || $sale->cancellation()->exists()) {
            return false;
        }
        $session = CardStoreSession::where('card_store_id', $sale->card_store_id)->where('active_slot', 1)->whereNull('closed_at')->first();

        return $session && $session->user_id === $sale->user_id && $sale->lines()->exists()
         && ! $sale->lines()->whereNotIn('card_inventory_movement_id', CardInventoryMovement::where('card_store_session_id', $session->id)->select('id'))->exists();
    }

    public function execute(User $actor, int $saleId, array $data): CardSaleCancellation
    {
        app(CardInventoryAuthorization::class)->authorize($actor, 'cancelSale');
        $v = Validator::make($data, ['reason' => ['required', 'string', 'min:10', 'max:2000', 'regex:/\S/u'], 'confirmed' => ['required', 'accepted'], 'request_key' => ['required', 'uuid']])->validate();
        $payload = ['sale_id' => $saleId, 'reason' => trim($v['reason'])];
        if (mb_strlen($payload['reason']) < 10) {
            throw ValidationException::withMessages(['reason' => 'Explica el motivo con al menos 10 caracteres.']);
        }
        $result = app(CardInventoryCommandRunner::class)->execute($actor, 'cancelSale', 'SALE_CANCELLATION', $v['request_key'], $payload, function (CardStore $store, User $user) use ($payload, $v): array {
            $sale = CardSale::whereKey($payload['sale_id'])->lockForUpdate()->firstOrFail();
            $session = CardStoreSession::where('card_store_id', $store->id)->where('active_slot', 1)->whereNull('closed_at')->lockForUpdate()->first();
            if ($sale->card_store_id !== $store->id || ! $session || CardSaleCancellation::where('card_sale_id', $sale->id)->exists()) {
                throw ValidationException::withMessages(['reason' => 'La venta ya está anulada o no hay una sesión abierta para corregirla.']);
            }
            $lines = $sale->lines()->orderBy('card_product_id')->lockForUpdate()->get();
            if ($lines->isEmpty() || $sale->payment_method !== 'CASH') {
                throw ValidationException::withMessages(['reason' => 'Esta venta no admite anulación desde Tarjetas.']);
            }
            $sourceMovements = CardInventoryMovement::whereIn('id', $lines->pluck('card_inventory_movement_id'))->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                $movement = $sourceMovements->get($line->card_inventory_movement_id);
                if (! $movement || $movement->card_store_session_id !== $session->id || $movement->user_id !== $sale->user_id || $session->user_id !== $sale->user_id) {
                    throw ValidationException::withMessages(['reason' => 'Solo se anulan ventas de la sesión que sigue abierta. No se modifica un turno cerrado ni otro relevo.']);
                }
            }
            if ($store->cash_balance_cents < $sale->total_cents) {
                throw ValidationException::withMessages(['reason' => 'No hay efectivo disponible suficiente para revertir esta venta. Revisa los depósitos y movimientos.']);
            }
            $stocks = CardStock::where('card_store_id', $store->id)->whereIn('card_product_id', $lines->pluck('card_product_id'))->orderBy('card_product_id')->lockForUpdate()->get()->keyBy('card_product_id');
            $before = $store->cash_balance_cents;
            $cash = $before;
            foreach ($lines as $line) {
                $stock = $stocks->get($line->card_product_id);
                if (! $stock || $stock->quantity > 1000000 - $line->quantity) {
                    throw ValidationException::withMessages(['reason' => 'El stock resultante supera el límite o el producto no tiene existencias configuradas.']);
                }
                $quantity = $stock->quantity;
                $cost = $stock->cost_value_cents;
                $restored = app(CardAverageCostCalculator::class)->receive($quantity, $cost, $line->quantity, $line->cost_cents);
                $stock->update(['quantity' => $quantity + $line->quantity, 'cost_value_cents' => $restored, 'cost_revision' => $stock->cost_revision + 1]);
                CardInventoryMovement::create(['card_store_id' => $store->id, 'card_store_session_id' => $session->id, 'card_product_id' => $line->card_product_id, 'user_id' => $user->id, 'kind' => 'SALE_CANCELLATION', 'quantity' => $line->quantity, 'total_cost_cents' => $line->cost_cents, 'cost_before_cents' => $cost, 'cost_after_cents' => $restored, 'stock_before' => $quantity, 'stock_after' => $stock->quantity, 'cash_delta_cents' => -$line->total_cents, 'cash_before_cents' => $cash, 'cash_after_cents' => $cash - $line->total_cents, 'product_snapshot' => $line->product_snapshot, 'reference' => 'Anulación venta #'.$sale->id]);
                $cash -= $line->total_cents;
            }
            if ($cash !== $before - $sale->total_cents) {
                throw new \LogicException('El total de la venta no coincide con sus líneas.');
            }
            $cancellation = CardSaleCancellation::create(['card_sale_id' => $sale->id, 'card_store_session_id' => $session->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'reason' => $payload['reason'], 'total_cents' => $sale->total_cents, 'total_quantity' => $sale->total_quantity, 'snapshot' => ['sale_id' => $sale->id, 'sold_by_user_id' => $sale->user_id, 'sold_by_name' => $sale->user?->name, 'cancelled_by_name' => $user->name, 'sold_at' => $sale->created_at->toIso8601String(), 'cash_before_cents' => $before, 'cash_after_cents' => $cash, 'lines' => $lines->map(fn ($line) => ['product_id' => $line->card_product_id, 'product_snapshot' => $line->product_snapshot, 'quantity' => $line->quantity, 'total_cents' => $line->total_cents])->all()], 'created_at' => now()]);
            $store->update(['cash_balance_cents' => $cash, 'revision' => $store->revision + 1]);

            return ['cancellation_id' => $cancellation->id];
        });

        return CardSaleCancellation::findOrFail($result['cancellation_id']);
    }
}
