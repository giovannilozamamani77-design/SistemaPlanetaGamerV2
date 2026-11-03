<?php

namespace App\Domain\Usuarios\Authorization;

use App\Models\User;

class EffectivePermissions
{
    public function allows(User $user, string $permission): bool
    {
        if (! $user->is_active) {
            return false;
        }
        $user->loadMissing('restrictedPermissions');
        if ($user->restrictedPermissions->contains('name', $permission)) {
            return false;
        }

        return $user->checkPermissionTo($permission, 'web');
    }
}
