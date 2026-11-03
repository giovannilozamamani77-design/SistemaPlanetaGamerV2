<?php

namespace App\Domain\Prestamos\Authorization;

use App\Domain\Prestamos\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class LoanAuthorization
{
    public function authorize(User $actor, string $ability): User
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize($ability, Loan::class);

        return $user;
    }
}
