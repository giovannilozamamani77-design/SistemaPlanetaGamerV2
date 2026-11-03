<?php

namespace App\Domain\Accesorios\Policies;

use App\Models\User;

class PricingPolicy
{
    public function configure(User $user): bool
    {
        return $user->is_active && $user->hasAnyRole(['administrador', 'encargado']) && $user->can('accesorios.precios.configurar');
    }
}
