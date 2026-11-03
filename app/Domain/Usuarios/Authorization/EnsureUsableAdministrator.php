<?php

namespace App\Domain\Usuarios\Authorization;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class EnsureUsableAdministrator
{
    public function execute(string $field = 'data.roles'): void
    {
        $administrators = User::role('administrador')->where('is_active', true)
            ->with(['roles.permissions', 'permissions', 'restrictedPermissions'])->get();

        if (! $administrators->contains(fn (User $admin): bool => $admin->can('panel.acceder') && $admin->can('usuarios.permisos.asignar') && $admin->can('usuarios.actualizar'))) {
            throw ValidationException::withMessages([$field => 'Debe conservar al menos un administrador activo con acceso y permisos de administración.']);
        }
    }
}
