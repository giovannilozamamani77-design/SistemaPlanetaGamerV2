<?php

namespace App\Domain\Tarjetas\Queries;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardStore;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CardSalesQuery
{
    public function __construct(private CardInventoryAuthorization $authorization) {}

    public function products(User $actor, ?string $mode = null): Builder
    {
        $this->authorization->authorize($actor, 'sell');

        return CardProduct::with('stock')->where('is_active', true)->when($mode === 'WHOLESALE', fn ($q) => $q->where('wholesale_enabled', true))->orderBy('company')->orderBy('face_value_cents');
    }

    public function summary(User $actor): array
    {
        $this->authorization->authorize($actor, 'sell');
        $store = CardStore::where('code', 'TIENDA')->first();

        return ['initialized' => (bool) $store?->initialized_at, 'cash_cents' => $store?->initialized_at ? $store->cash_balance_cents : null, 'wholesale_min_quantity' => $store?->wholesale_min_quantity ?? 5];
    }

    public function sales(User $actor): Builder
    {
        $this->authorization->authorize($actor, 'sell');

        return CardSale::query()->with(['user', 'lines']);
    }
}
