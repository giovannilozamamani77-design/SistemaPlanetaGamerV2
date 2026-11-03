<?php

namespace App\Domain\Tarjetas\Providers;

use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Policies\CardInventoryPolicy;
use App\Domain\Tarjetas\Policies\EntelDigitalPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TarjetasServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/tarjetas'));
        Gate::policy(CardProduct::class, CardInventoryPolicy::class);
        Gate::policy(EntelDigitalAccount::class, EntelDigitalPolicy::class);
    }
}
