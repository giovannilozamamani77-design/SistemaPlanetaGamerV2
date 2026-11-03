<?php

namespace App\Domain\Notificaciones\Queries;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class PanelInboxVersionQuery
{
    public function execute(User $actor): string
    {
        $user = User::findOrFail($actor->id);
        if (! $user->is_active) {
            throw new AuthorizationException;
        }
        $user->load(['roles.permissions', 'permissions', 'restrictedPermissions']);
        Gate::forUser($user)->authorize('panel.acceder');
        $scope = [$user->id, $user->is_active, $user->roles->pluck('name')->sort()->values()->all(),
            $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            $user->restrictedPermissions->pluck('name')->sort()->values()->all()];

        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR).app(PanelInboxRevision::class)->token());
    }
}
