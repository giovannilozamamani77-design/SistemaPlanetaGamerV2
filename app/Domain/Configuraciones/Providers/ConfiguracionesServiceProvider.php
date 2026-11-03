<?php

namespace App\Domain\Configuraciones\Providers;

use App\Domain\Configuraciones\Queries\PersonnelRules;
use Illuminate\Support\ServiceProvider;

class ConfiguracionesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PersonnelRules::class, fn (): PersonnelRules => new PersonnelRules);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/configuraciones'));
    }
}
