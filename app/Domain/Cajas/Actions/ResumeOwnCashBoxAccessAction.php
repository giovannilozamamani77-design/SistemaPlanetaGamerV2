<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Services\CashBoxScheduleSafety;
use App\Domain\ControlHorario\Actions\CaptureCashBoxContinuityAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class ResumeOwnCashBoxAccessAction
{
    public function execute(User $actor, ?int $boxId = null, bool $allowFallback = false): ?CashBoxAccess
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('cajas.operar');
        if (! $user->is_active) {
            throw new AuthorizationException;
        }
        $session = CashBoxSession::where('user_id', $user->id)->where('status', 'ABIERTA')->whereNotNull('active_slot')
            ->when($boxId && ! $allowFallback, fn ($q) => $q->where('cash_box_id', $boxId))
            ->when($boxId && $allowFallback, fn ($q) => $q->orderByRaw('CASE WHEN cash_box_id = ? THEN 0 ELSE 1 END', [$boxId]))->latest('id')->first();
        if (! $session) {
            return null;
        }

        $access = CashBoxAccess::where('user_id', $user->id)->where('cash_box_id', $session->cash_box_id)->where('cash_box_session_id', $session->id)->latest('id')->first();
        if ($access) {
            app(CashBoxScheduleSafety::class)->attempt(fn () => app(CaptureCashBoxContinuityAction::class)->onResume($user, $session));
        }

        return $access;
    }
}
