<?php

namespace App\Domain\Tarjetas\Queries;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Calculators\CardCashFundingCalculator;
use App\Domain\Tarjetas\Models\CardStore;
use App\Models\User;

class CardCashFundingQuery
{
    public function quote(User $user, mixed $amount): array
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'cashFunding');
        $store = CardStore::where('code', 'TIENDA')->first();
        $before = $store?->initialized_at ? $store->cash_balance_cents : null;

        return ['cash_cents' => $before, 'after_cents' => app(CardCashFundingCalculator::class)->preview($before, $amount)];
    }
}
