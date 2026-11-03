<?php

namespace App\Domain\Usuarios\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('administrador') && $user->can('usuarios.ver') && $user->can('usuarios.permisos.asignar');
    }

    public function view(User $user, Role $role): bool
    {
        return $role->guard_name === 'web' && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('usuarios.actualizar');
    }

    public function update(User $user, Role $role): bool
    {
        return $role->guard_name === 'web' && $this->create($user);
    }

    public function delete(User $user, Role $role): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
