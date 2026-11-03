<?php

namespace App\Domain\Cajas\Queries;

use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class CashBoxPreviousHandoverQuery
{
    public function execute(User $actor, int $accessId): ?array
    {
        Gate::forUser(User::findOrFail($actor->id))->authorize('cajas.operar');
        $access = CashBoxAccess::findOrFail($accessId);
        if ($access->user_id !== $actor->id || $access->cash_box_session_id) {
            throw new AuthorizationException;
        }
        $previous = CashBoxSession::where('cash_box_id', $access->cash_box_id)->latest('id')->first();
        if (! $previous || ! $previous->released_at || $previous->handover_balances !== $access->expected_balances) {
            return null;
        }
        $close = CashBoxClose::where('cash_box_session_id', $previous->id)->whereNull('cancelled_at')->latest('id')->first();

        return ['balances' => $previous->handover_balances, 'cash_count' => $close?->count_details['cash_count'] ?? null];
    }
}
