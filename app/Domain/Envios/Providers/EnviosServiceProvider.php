<?php

namespace App\Domain\Envios\Providers;

use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use App\Domain\Envios\Queries\InternationalCustody;
use Illuminate\Support\ServiceProvider;

class EnviosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReservedCashBoxFunds::class, InternationalCustody::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/envios'));
    }
}
