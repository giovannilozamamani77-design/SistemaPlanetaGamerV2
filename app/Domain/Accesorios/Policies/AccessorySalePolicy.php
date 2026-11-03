<?php

namespace App\Domain\Accesorios\Policies;

use App\Models\User;

class AccessorySalePolicy
{
    public function create(User $u): bool
    {
        return $u->is_active && $u->can('accesorios.ventas.registrar');
    }

    public function viewAny(User $u): bool
    {
        return $u->is_active && $u->can('accesorios.ventas.ver');
    }
}
