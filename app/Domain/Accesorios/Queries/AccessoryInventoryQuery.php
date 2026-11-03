<?php

namespace App\Domain\Accesorios\Queries;

use App\Domain\Accesorios\Calculators\AverageCostCalculator;
use App\Domain\Accesorios\Calculators\PricingCalculator;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class AccessoryInventoryQuery
{
    public function authorize(User $actor, string $ability = 'viewAny'): User
    {
        $u = User::findOrFail($actor->id);
        Gate::forUser($u)->authorize($ability, AccessoryProduct::class);

        return $u;
    }

    public function products(User $u): Builder
    {
        $this->authorize($u);

        return AccessoryProduct::query();
    }

    public function searchSalePrice(Builder $query, string $search): Builder
    {
        $amount = str_replace(',', '.', trim($search));
        if (! preg_match('/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/', $amount)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('sale_price_cents', Bolivianos::cents($amount));
    }

    public function cost(AccessoryProduct $p): string
    {
        return app(AverageCostCalculator::class)->display($p->quantity, $p->stock_value_cents);
    }

    public function belowCost(AccessoryProduct $p): bool
    {
        return $p->quantity > 0 && $p->sale_price_cents * $p->quantity < $p->stock_value_cents;
    }

    public function suggestion(User $u, AccessoryProduct $p): ?array
    {
        $this->authorize($u, 'manage');
        $p = $p->fresh();
        if ($p->quantity === 0) {
            return null;
        }
        $s = PricingSettings::where('code', 'GENERAL')->first()?->toArray() ?? PricingInput::defaults();

        return app(PricingCalculator::class)->calculate($s, ['kind' => 'ACCESSORY', 'historical_cost' => Bolivianos::format(app(AverageCostCalculator::class)->unitCostCeiling($p->quantity, $p->stock_value_cents)), 'manual_price' => Bolivianos::format($p->sale_price_cents)]);
    }

    public function priceForm(User $u, AccessoryProduct $p): array
    {
        $this->authorize($u, 'manage');
        $p = $p->fresh();
        $s = PricingSettings::where('code', 'GENERAL')->first()?->toArray() ?? PricingInput::defaults();

        return ['price_mode' => $s['rules']['ACCESSORY']['mode'], 'manual_price' => Bolivianos::formatCompact($p->sale_price_cents), 'expected_revision' => $p->revision, 'expected_pricing_revision' => $s['revision']];
    }

    public function entries(User $u, AccessoryProduct $p): Builder
    {
        $this->authorize($u, 'manage');

        return $p->entries()->getQuery()->orderByDesc('id');
    }
}
