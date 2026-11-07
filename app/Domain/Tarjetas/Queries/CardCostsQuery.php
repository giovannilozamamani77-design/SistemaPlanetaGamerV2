<?php

namespace App\Domain\Tarjetas\Queries;

use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSaleLine;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Models\EntelRecharge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CardCostsQuery
{
    public function stocks(User $actor): Builder
    {
        Gate::forUser($actor->fresh())->authorize('initialize', CardProduct::class);

        return CardStock::query()->whereHas('store', fn (Builder $q) => $q->where('code', 'TIENDA'))->with('product');
    }

    public function digital(User $actor): array
    {
        Gate::forUser($actor->fresh())->authorize('viewAny', EntelDigitalAccount::class);

        return EntelDigitalAccount::orderBy('kind')->get()->all();
    }

    public function margins(User $actor): array
    {
        Gate::forUser($actor->fresh())->authorize('initialize', CardProduct::class);
        Gate::forUser($actor->fresh())->authorize('viewAny', EntelDigitalAccount::class);
        $physical = CardSaleLine::query()->whereNotIn('card_sale_id', DB::table('card_sale_cancellations')->select('card_sale_id'))->selectRaw('COUNT(*) as records, COALESCE(SUM(CASE WHEN cost_cents IS NULL THEN 1 ELSE 0 END),0) as pending, COALESCE(SUM(gross_margin_cents),0) as known_margin')->first();
        $digital = EntelRecharge::query()->select('kind')->selectRaw('COUNT(*) as records, COALESCE(SUM(CASE WHEN cost_cents IS NULL THEN 1 ELSE 0 END),0) as pending, COALESCE(SUM(gross_margin_cents),0) as known_margin')->groupBy('kind')->get()->keyBy('kind');
        $result = [];
        foreach (['PHYSICAL' => $physical, 'CREDIT' => $digital->get('CREDIT'), 'PACKAGES' => $digital->get('PACKAGES')] as $key => $row) {
            $result[$key] = ['records' => (int) ($row?->records ?? 0), 'pending' => (int) ($row?->pending ?? 0), 'known_margin_cents' => (int) ($row?->known_margin ?? 0)];
        }

        return $result;
    }
}
