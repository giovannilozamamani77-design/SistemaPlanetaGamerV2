<?php

namespace App\Domain\Cajas\Providers;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxCorrection;
use App\Domain\Cajas\Policies\CashBoxPolicy;
use App\Domain\Cajas\Policies\CashBoxWorkflowPolicy;
use App\Domain\Cajas\Queries\CashBoxTypeCatalog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CajasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CashBoxTypeCatalog::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/cajas'));
        Gate::policy(CashBox::class, CashBoxPolicy::class);
        foreach ([CashBoxCorrection::class] as $model) {
            Gate::policy($model, CashBoxWorkflowPolicy::class);
        }
    }
}
