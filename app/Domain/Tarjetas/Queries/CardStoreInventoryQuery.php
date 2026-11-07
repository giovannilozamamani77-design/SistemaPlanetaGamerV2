<?php

namespace App\Domain\Tarjetas\Queries;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Database\Eloquent\Builder;

class CardStoreInventoryQuery
{
    public function __construct(private CardInventoryAuthorization $authorization) {}

    public function products(User $actor): Builder
    {
        $this->authorization->authorize($actor, 'viewAny');

        return CardProduct::query()->with('stock');
    }

    public function options(User $actor, ?array $companies = null): array
    {
        $query = $this->products($actor)->where('is_active', true)->orderBy('company')->orderBy('face_value_cents');
        if ($companies) {
            $query->whereIn('company', $companies);
        }

        return $query->get()->mapWithKeys(fn (CardProduct $p): array => [$p->id => $p->label()])->all();
    }

    public function summary(User $actor): array
    {
        $this->authorization->authorize($actor, 'viewAny');
        $store = CardStore::where('code', 'TIENDA')->first();
        $totals = CardStock::where('card_store_id', $store?->id ?? 0)->selectRaw('COALESCE(SUM(quantity),0) as units, COALESCE(SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END),0) as stocked_products')->first();

        return ['initialized' => $store?->initialized_at !== null, 'cash' => $store?->initialized_at ? 'Bs '.Bolivianos::formatCompact($store->cash_balance_cents) : 'Sin conteo inicial', 'units' => (int) $totals->units, 'stocked_products' => (int) $totals->stocked_products];
    }

    public function movements(User $actor): Builder
    {
        $this->authorization->authorize($actor, 'history');

        return CardInventoryMovement::query()->with('user');
    }

    public static function kinds(): array
    {
        return ['COST_VALUATION' => 'Costo real completado por Administración', 'SURPLUS_RESOLVE' => 'Resolución administrativa de sobrante', 'COUNT_OPEN_CASH' => 'Conteo de apertura · Efectivo', 'COUNT_CLOSE_CASH' => 'Conteo de cierre · Efectivo', 'COUNT_OPEN_STOCK' => 'Conteo de apertura · Tarjetas', 'COUNT_CLOSE_STOCK' => 'Conteo de cierre · Tarjetas', 'SURPLUS_RELEASE_CASH' => 'Liberación de sobrante reservado · Efectivo', 'SURPLUS_RELEASE_STOCK' => 'Liberación de sobrante reservado · Tarjetas', 'ADMIN_CASH_IN' => 'Efectivo entregado por Administración', 'ADMIN_CASH_DEPOSIT' => 'Depósito a Administración', 'INITIAL_CASH' => 'Efectivo inicial', 'INITIAL_STOCK' => 'Tarjetas iniciales', 'PURCHASE' => 'Compra pagada de tarjetas', 'ADMIN_DELIVERY' => 'Entrega de Administración', 'RECHARGE_CREDIT' => 'Recarga Entel · Crédito', 'RECHARGE_PACKAGES' => 'Recarga Entel · Paquetes', 'SALE_CANCELLATION' => 'Anulación de venta', 'SALE_NORMAL' => 'Venta normal', 'SALE_WHOLESALE' => 'Venta mayorista'];
    }

    public static function signedMoney(int $cents): string
    {
        return ($cents < 0 ? '−' : ($cents > 0 ? '+' : '')).'Bs '.Bolivianos::formatCompact(abs($cents));
    }
}
