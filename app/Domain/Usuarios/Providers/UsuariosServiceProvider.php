<?php

namespace App\Domain\Usuarios\Providers;

use App\Domain\Usuarios\Authorization\EffectivePermissions;
use App\Domain\Usuarios\Commands\InitializeAdministrator;
use App\Domain\Usuarios\Policies\RolePolicy;
use App\Domain\Usuarios\Policies\UserPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class UsuariosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        $this->commands([InitializeAdministrator::class]);
        $this->loadMigrationsFrom(database_path('migrations/usuarios'));
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->is_active) {
                return false;
            }
            if (! str_contains($ability, '.')) {
                return null;
            }

            return app(EffectivePermissions::class)->allows($user, $ability);
        });
    }
}
