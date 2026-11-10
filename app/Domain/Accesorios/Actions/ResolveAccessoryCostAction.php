<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\AccessoryStockEntry;
use App\Domain\Accesorios\Queries\AccessoryInventoryQuery;
use App\Domain\Comisiones\Calculators\CommissionCalculator;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveAccessoryCostAction
{
    public function execute(User $actor, int $productId, mixed $amount, int $revision): void
    {
        app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');
        $cost = PricingInput::money($amount, 'unit_cost');

        DB::transaction(function () use ($actor, $productId, $cost, $revision): void {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $admin = app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');
            $product = AccessoryProduct::whereKey($productId)->lockForUpdate()->firstOrFail();
            if (! $product->cost_pending || $product->revision !== $revision) {
                throw ValidationException::withMessages(['unit_cost' => 'El inventario cambió. Abre nuevamente el formulario.']);
            }
            $initial = (int) AccessoryStockEntry::where('product_id', $productId)->where('kind', 'INITIAL')->sum('quantity');
            if ($cost * $initial > PricingInput::MAX_CENTS) {
                throw ValidationException::withMessages(['unit_cost' => 'El costo total supera el límite permitido.']);
            }
            $at = now();
            DB::table('accessory_cost_resolutions')->insert([
                'product_id' => $productId, 'user_id' => $admin->id, 'unit_cost_cents' => $cost,
                'remaining_quantity' => $product->quantity, 'created_at' => $at,
            ]);
            $lines = AccessorySaleLine::with('sale')->where('product_id', $productId)->whereNull('cost_cents');
            foreach ($lines->lazyById() as $line) {
                $snapshot = $line->pending_commission_snapshot;
                CommissionEntry::create([
                    'sale_line_id' => $line->id, 'user_id' => $line->sale->user_id, 'actor_name' => $line->sale->actor_name,
                    'amount_cents' => app(CommissionCalculator::class)->calculate([
                        'kind' => $line->kind, 'cost_cents' => $cost * $line->quantity,
                        'total_cents' => $line->total_cents, 'unit_price_cents' => $line->unit_price_cents,
                        'quantity' => $line->quantity,
                    ], $snapshot['commission_rules']),
                    'snapshot' => $snapshot + ['resolved_unit_cost_cents' => $cost, 'resolved_by' => $admin->id],
                    'created_at' => $at,
                ]);
            }
            $product->update([
                'cost_pending' => false, 'reference_cost_cents' => $cost,
                'stock_value_cents' => $cost * $product->quantity, 'revision' => $product->revision + 1,
            ]);
        }, 3);
    }
}
