<?php

namespace App\Domain\Reportes\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ReportesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/reportes'));

        Gate::define('viewDailyReports', function (User $actor): bool {
            $user = User::find($actor->id);

            return $user !== null && $user->is_active && $user->hasRole('administrador')
                && Gate::forUser($user)->allows('panel.acceder');
        });
    }
}
