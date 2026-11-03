<?php

namespace App\Domain\Envios\Authorization;

use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class TransferAuthorization
{
    public function admin(User $user): void
    {
        if (! config('envios.enabled', false) || ! $user->is_active) {
            throw new AuthorizationException;
        }
        Gate::forUser($user)->authorize('envios.administrar');
        if (! $user->hasRole('administrador')) {
            throw new AuthorizationException;
        }
    }

    public function owner(User $user, CashBoxSession $session, int $accessId): void
    {
        if (! config('envios.enabled', false) || ! $user->is_active) {
            throw new AuthorizationException;
        }
        Gate::forUser($user)->authorize('envios.registrar');
        Gate::forUser($user)->authorize('cajas.operar');
        $access = CashBoxAccess::find($accessId);
        if ($session->user_id !== $user->id || ! $access || $access->user_id !== $user->id || $access->cash_box_session_id !== $session->id) {
            throw new AuthorizationException('Ingresa a esta caja con tu PIN personal. Solo su titular puede registrar el movimiento.');
        }
    }
}
