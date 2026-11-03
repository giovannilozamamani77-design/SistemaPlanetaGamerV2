<?php

namespace App\Domain\Usuarios\Authorization;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdministrativeReview
{
    public function authorize(User $actor, string $permission, ?int $requester = null, bool $exception = false, string $reason = ''): User
    {
        $admin = User::findOrFail($actor->id);
        if (! $admin->hasRole('administrador')) {
            throw new AuthorizationException('Solo Administración puede confirmar.');
        }
        Gate::forUser($admin)->authorize($permission);
        if ($requester === $admin->id) {
            if (User::role('administrador')->where('is_active', true)->whereKeyNot($admin->id)->exists()) {
                throw new AuthorizationException('Debe confirmar otro administrador.');
            }
            if (! $exception || trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => 'La autoaprobación excepcional requiere confirmación y motivo.']);
            }
        }

        return $admin;
    }
}
