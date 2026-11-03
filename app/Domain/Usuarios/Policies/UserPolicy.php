<?php

namespace App\Domain\Usuarios\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('usuarios.ver');
    }

    public function view(User $user, User $record): bool
    {
        return $user->can('usuarios.ver');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('administrador') && $user->can('usuarios.crear') && $user->can('usuarios.permisos.asignar');
    }

    public function update(User $user, User $record): bool
    {
        return $user->hasRole('administrador') && $user->can('usuarios.actualizar') && $user->can('usuarios.permisos.asignar');
    }

    public function delete(User $user, User $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
