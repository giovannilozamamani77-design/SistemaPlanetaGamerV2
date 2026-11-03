<?php

namespace App\Domain\Prestamos\Providers;

use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Policies\LoanPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class PrestamosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/prestamos'));
        Gate::policy(Loan::class, LoanPolicy::class);
    }
}
