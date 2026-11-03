<?php

namespace App\Domain\Tarjetas\Queries;

use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Models\EntelDigitalMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class EntelDigitalQuery
{
    public function accounts(User $actor): array
    {
        Gate::forUser($actor->fresh())->authorize('viewAny', EntelDigitalAccount::class);

        return EntelDigitalAccount::orderBy('kind')->get()->keyBy('kind')->all();
    }

    public function movements(User $actor): Builder
    {
        Gate::forUser($actor->fresh())->authorize('viewAny', EntelDigitalAccount::class);

        return EntelDigitalMovement::query()->with(['account', 'user']);
    }
}
