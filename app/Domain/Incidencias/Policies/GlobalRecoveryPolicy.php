<?php

namespace App\Domain\Incidencias\Policies;

use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Models\User;

class GlobalRecoveryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('administrador') && $user->can('incidencias.reposiciones.confirmar');
    }

    public function view(User $user, GlobalRecovery $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('incidencias.declarar_aporte');
    }

    public function update(User $user, GlobalRecovery $record): bool
    {
        return false;
    }

    public function delete(User $user, GlobalRecovery $record): bool
    {
        return false;
    }
}
