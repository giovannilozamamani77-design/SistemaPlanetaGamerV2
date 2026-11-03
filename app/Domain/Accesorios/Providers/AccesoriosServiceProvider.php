<?php

namespace App\Domain\Accesorios\Providers;

use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Policies\AccessoryInventoryPolicy;
use App\Domain\Accesorios\Policies\AccessorySalePolicy;
use App\Domain\Accesorios\Policies\PricingPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AccesoriosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/accesorios'));
        Gate::policy(PricingSettings::class, PricingPolicy::class);
        Gate::policy(AccessoryProduct::class, AccessoryInventoryPolicy::class);
        Gate::policy(AccessorySale::class, AccessorySalePolicy::class);
    }
}
