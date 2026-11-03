<?php

namespace App\Domain\ControlHorario\Policies;

use App\Models\User;

class TimeIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('administrador') && $user->can('controlhorario.revisar');
    }
}
