<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Calculators\CardAverageCostCalculator;
use App\Domain\Tarjetas\Calculators\CardSaleCalculator;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordCardSaleAction
{
    public function __construct(private CardInventoryAuthorization $authorization, private CardInventoryCommandRunner $commands, private CardSaleCalculator $calculator) {}

    public function execute(User $actor, array $data): CardSale
    {
        $this->authorization->authorize($actor, 'sell');
        $v = Validator::make($data, ['mode' => ['required', Rule::in(['NORMAL', 'WHOLESALE'])], 'payment_method' => ['required', Rule::in(['CASH'])],
            'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.product_id' => ['required', 'integer', 'distinct'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'], 'lines.*.unit_price' => ['required'], 'request_key' => ['required', 'uuid']])->validate();
        $lines = [];
        foreach ($v['lines'] as $index => $line) {
            $lines[] = ['product_id' => (int) $line['product_id'], 'quantity' => (int) $line['quantity'], 'unit_price_cents' => CardInventoryInput::money($line['unit_price'], 'lines.'.$index.'.unit_price')];
        }
        $payload = ['mode' => $v['mode'], 'payment_method' => 'CASH', 'lines' => $lines];
        $result = $this->commands->execute($actor, 'sell', 'SALE', $v['request_key'], $payload, function (CardStore $store, User $user) use ($payload, $v): array {
            if (! $store->initialized_at) {
                throw ValidationException::withMessages(['lines' => 'Administración debe registrar primero el inventario y efectivo iniciales.']);
            }
            $totals = $this->calculator->total($payload['lines']);
            if ($payload['mode'] === 'WHOLESALE' && $totals['total_quantity'] < $store->wholesale_min_quantity) {
                throw ValidationException::withMessages(['lines' => 'La venta mayorista requiere al menos '.$store->wholesale_min_quantity.' unidades combinadas.']);
            }
            if ($totals['total_cents'] > CardSaleCalculator::MAX_CENTS - $store->cash_balance_cents) {
                throw ValidationException::withMessages(['lines' => 'El efectivo resultante supera el importe admitido.']);
            }
            $ids = array_column($payload['lines'], 'product_id');
            sort($ids);
            $products = CardProduct::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $stocks = CardStock::where('card_store_id', $store->id)->whereIn('card_product_id', $ids)->orderBy('card_product_id')->lockForUpdate()->get()->keyBy('card_product_id');
            foreach ($payload['lines'] as $i => $line) {
                $product = $products->get($line['product_id']);
                $stock = $stocks->get($line['product_id']);
                if (! $product || ! $product->is_active || ! $stock) {
                    throw ValidationException::withMessages(['lines.'.$i.'.product_id' => 'Selecciona un producto activo y disponible del inventario.']);
                }
                if ($payload['mode'] === 'WHOLESALE' && ! $product->wholesale_enabled) {
                    throw ValidationException::withMessages(['lines.'.$i.'.product_id' => 'Este corte no está habilitado para venta mayorista. Usa venta normal o consulta a Administración.']);
                }
                if ($stock->quantity < $line['quantity']) {
                    throw ValidationException::withMessages(['lines.'.$i.'.quantity' => 'Stock insuficiente: quedan '.$stock->quantity.' unidades de '.$product->label().'.']);
                }
            }
            $cash = $store->cash_balance_cents;
            $sale = CardSale::create(['card_store_id' => $store->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'mode' => $payload['mode'], 'payment_method' => 'CASH', 'wholesale_min_quantity' => $store->wholesale_min_quantity, ...$totals, 'cash_before_cents' => $cash, 'cash_after_cents' => $cash + $totals['total_cents'], 'created_at' => now()]);
            foreach ($payload['lines'] as $line) {
                $product = $products[$line['product_id']];
                $stock = $stocks[$line['product_id']];
                $before = $stock->quantity;
                $amount = $line['quantity'] * $line['unit_price_cents'];
                $snapshot = $product->snapshot() + ['label' => $product->label()];
                $valueBefore = $stock->cost_value_cents;
                $valuation = app(CardAverageCostCalculator::class)->consume($before, $valueBefore, $line['quantity']);
                $cost = $valuation['consumed'];
                $stock->update(['quantity' => $before - $line['quantity'], 'cost_value_cents' => $valuation['remaining'], 'cost_revision' => $stock->cost_revision + 1]);
                $movement = CardInventoryMovement::create(['card_store_id' => $store->id, 'card_product_id' => $product->id, 'user_id' => $user->id, 'kind' => 'SALE_'.$payload['mode'], 'quantity' => $line['quantity'], 'unit_cost_cents' => null, 'total_cost_cents' => $cost, 'cost_before_cents' => $valueBefore, 'cost_after_cents' => $valuation['remaining'], 'cash_delta_cents' => $amount, 'stock_before' => $before, 'stock_after' => $stock->quantity, 'cash_before_cents' => $cash, 'cash_after_cents' => $cash + $amount, 'product_snapshot' => $snapshot, 'reference' => 'Venta #'.$sale->id]);
                $sale->lines()->create(['card_product_id' => $product->id, 'card_inventory_movement_id' => $movement->id, 'quantity' => $line['quantity'], 'unit_price_cents' => $line['unit_price_cents'], 'total_cents' => $amount, 'cost_cents' => $cost, 'gross_margin_cents' => $cost === null ? null : $amount - $cost, 'product_snapshot' => $snapshot]);
                $cash += $amount;
            }
            $store->update(['cash_balance_cents' => $cash, 'revision' => $store->revision + 1]);

            return ['sale_id' => $sale->id];
        });

        return CardSale::with('lines')->findOrFail($result['sale_id']);
    }
}
