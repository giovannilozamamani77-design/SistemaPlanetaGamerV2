<?php

namespace App\Domain\Incidencias\Providers;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Domain\Incidencias\Policies\EconomicIncidentPolicy;
use App\Domain\Incidencias\Policies\GlobalRecoveryPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class IncidenciasServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(EconomicIncident::class, EconomicIncidentPolicy::class);
        Gate::policy(GlobalRecovery::class, GlobalRecoveryPolicy::class);
        $this->loadMigrationsFrom(database_path('migrations/incidencias'));
    }
}
