<?php

namespace App\Domain\Cajas\Policies;

use App\Models\User;

class CashBoxWorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('administrador') && $user->can('cajas.operar') && $user->can('cajas.liberar');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function delete(User $user, mixed $record): bool
    {
        return false;
    }
}
