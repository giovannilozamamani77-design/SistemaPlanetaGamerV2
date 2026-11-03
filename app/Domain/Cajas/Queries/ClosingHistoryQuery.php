<?php

namespace App\Domain\Cajas\Queries;

use App\Domain\Cajas\Models\CashBoxClose;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClosingHistoryQuery
{
    public function forUser(User $actor): Builder
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('cajas.operar');
        $query = CashBoxClose::query()->with(['box:id,name', 'user:id,name']);
        if (! $user->hasRole('administrador') || ! $user->can('cajas.liberar')) {
            $query->select(['id', 'cash_box_id', 'user_id', 'period', 'created_at', 'real_balances', 'released_at', 'cancelled_at']);
        }

        return $query;
    }

    public function dates(Builder $query, array $data): Builder
    {
        $values = Validator::make($data, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        if (filled($values['from'] ?? null) && filled($values['until'] ?? null) && $values['until'] < $values['from']) {
            throw ValidationException::withMessages(['until' => 'La fecha final debe ser igual o posterior a la inicial.']);
        }
        if (filled($values['from'] ?? null)) {
            $query->where('cash_box_closes.created_at', '>=', CarbonImmutable::parse($values['from'], 'America/La_Paz')->startOfDay()->utc());
        }
        if (filled($values['until'] ?? null)) {
            $query->where('cash_box_closes.created_at', '<', CarbonImmutable::parse($values['until'], 'America/La_Paz')->startOfDay()->addDay()->utc());
        }

        return $query;
    }
}
