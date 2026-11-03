<?php

namespace App\Domain\Accesorios\Policies;

use App\Models\User;

class AccessoryInventoryPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->is_active && $u->can('accesorios.inventario.ver');
    }

    public function manage(User $u): bool
    {
        return $u->is_active && $u->hasAnyRole(['administrador', 'encargado']) && $u->can('accesorios.inventario.gestionar');
    }
}
