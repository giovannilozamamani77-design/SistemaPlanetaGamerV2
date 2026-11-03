<?php

namespace App\Domain\Liquidaciones\Queries;

use App\Domain\Cajas\Models\CashBoxLiquidation;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class LiquidationHistoryQuery
{
    public function forUser(User $actor): Builder
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('cajas.operar');
        $query = CashBoxLiquidation::query()->with(['box:id,name', 'user:id,name', 'close.destinationAccount']);
        app(AdministrativeReview::class)->authorize($user, 'cajas.liberar');

        return $query;
    }

    public function dates(Builder $query, array $data): Builder
    {
        $values = Validator::make($data, ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d']])->validate();
        if (! empty($values['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($values['from'], 'America/La_Paz')->startOfDay()->utc());
        }
        if (! empty($values['until'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($values['until'], 'America/La_Paz')->startOfDay()->addDay()->utc());
        }

        return $query;
    }
}
