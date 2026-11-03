<?php

namespace App\Domain\Tarjetas\Authorization;

use App\Domain\Tarjetas\Models\CardProduct;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CardInventoryAuthorization
{
    public function authorize(User $actor, string $ability): User
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize($ability, CardProduct::class);

        return $user;
    }
}
