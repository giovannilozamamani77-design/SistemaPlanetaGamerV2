<?php

namespace App\Domain\Cajas\Policies;

use App\Domain\Cajas\Models\CashBox;
use App\Models\User;

class CashBoxPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('administrador') && $user->can('cajas.catalogo.ver');
    }

    public function view(User $user, CashBox $box): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('administrador') && $user->can('cajas.catalogo.gestionar');
    }

    public function update(User $user, CashBox $box): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, CashBox $box): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
