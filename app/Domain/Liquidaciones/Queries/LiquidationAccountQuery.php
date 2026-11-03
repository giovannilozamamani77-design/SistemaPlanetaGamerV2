<?php

namespace App\Domain\Liquidaciones\Queries;

use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LiquidationAccountQuery
{
    public function current(User $actor): ?object
    {
        return $this->forKey($actor, 'legacy');
    }

    public function forConfiguration(User $actor, CashBoxConfiguration $configuration, bool $lock = false): ?object
    {
        return $this->forKey($actor, $configuration->settlement_destination_key ?? 'legacy', $lock);
    }

    public function forKey(User $actor, string $key, bool $lock = false): ?object
    {
        Gate::forUser(User::findOrFail($actor->id))->authorize('cajas.operar');

        return DB::table('cash_box_commission_accounts')->where('account_key', $key)->orderBy('is_demo')->orderByDesc('id')->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public function catalog(User $actor): Collection
    {
        app(AdministrativeReview::class)->authorize($actor, 'cajas.catalogo.gestionar');

        return DB::table('cash_box_commission_accounts')->where('is_demo', false)
            ->whereIn('id', DB::table('cash_box_commission_accounts')->selectRaw('MAX(id)')->where('is_demo', false)->groupBy('account_key'))
            ->orderBy('name')->get();
    }

    public function demo(User $actor): ?object
    {
        Gate::forUser(User::findOrFail($actor->id))->authorize('cajas.operar');

        return DB::table('cash_box_commission_accounts')->where('is_demo', true)->latest('id')->first();
    }
}
