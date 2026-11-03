<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxCorrection;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MarkCashBoxCorrectionReadAction
{
    public function execute(User $actor, CashBoxCorrection $target, int $seenVersion): void
    {
        $fresh = User::findOrFail($actor->id);
        Gate::forUser($fresh)->authorize('cajas.operar');
        DB::transaction(function () use ($fresh, $target, $seenVersion): void {
            $user = User::whereKey($fresh->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('cajas.operar');
            $session = CashBoxSession::findOrFail($target->cash_box_session_id);
            CashBox::whereKey($session->cash_box_id)->lockForUpdate()->firstOrFail();
            $session = CashBoxSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->user_id !== $user->id) {
                throw new AuthorizationException;
            }
            $request = CashBoxCorrection::whereKey($target->id)->where('cash_box_session_id', $session->id)->lockForUpdate()->firstOrFail();
            // A stale window must never acknowledge a newer response.
            if ($seenVersion <= 0 || $request->response_version !== $seenVersion || $request->viewed_response_version >= $seenVersion) {
                return;
            }
            $request->update(['viewed_response_version' => $seenVersion, 'response_read_at' => now()]);
        });
    }
}
