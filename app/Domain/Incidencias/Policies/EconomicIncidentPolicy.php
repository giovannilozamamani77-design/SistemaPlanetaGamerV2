<?php

namespace App\Domain\Incidencias\Policies;

use App\Models\User;

class EconomicIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('administrador') && $user->can('incidencias.ver_todos');
    }
}
