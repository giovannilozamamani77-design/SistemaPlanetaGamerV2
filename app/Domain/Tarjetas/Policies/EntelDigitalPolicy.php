<?php

namespace App\Domain\Tarjetas\Policies;

use App\Models\User;

class EntelDigitalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->hasRole('administrador') && $user->can('tarjetas.entel.ver');
    }

    public function record(User $user): bool
    {
        return $this->viewAny($user) && $user->can('tarjetas.entel.registrar');
    }
}
