<?php

namespace App\Domain\Cajas\Queries;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CashBoxCorrectionAlertsQuery
{
    private const COUNTS = "COALESCE(SUM(CASE WHEN c.response_version > c.viewed_response_version THEN 1 ELSE 0 END), 0) AS unread, COALESCE(SUM(CASE WHEN c.status = 'DEVUELTA' THEN 1 ELSE 0 END), 0) AS returned";

    public function execute(User $actor, int $accessId): array
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('cajas.operar');
        $counts = DB::table('cash_box_accesses as a')
            ->join('cash_box_sessions as s', 's.id', '=', 'a.cash_box_session_id')
            ->leftJoin('cash_box_corrections as c', 'c.cash_box_session_id', '=', 's.id')
            ->where('a.id', $accessId)->where('a.user_id', $user->id)->where('s.user_id', $user->id)
            ->where('s.status', 'ABIERTA')->whereNotNull('s.active_slot')
            ->selectRaw(self::COUNTS)->groupBy('s.id')->first();

        return ['active' => $counts !== null, ...$this->counts($counts)];
    }

    /** The workspace must authorize the session before calling this method. */
    public function forSession(int $sessionId): array
    {
        return $this->counts(DB::table('cash_box_corrections as c')->where('c.cash_box_session_id', $sessionId)->selectRaw(self::COUNTS)->first());
    }

    private function counts(?object $counts): array
    {
        return ['unread' => (int) ($counts?->unread ?? 0), 'returned' => (int) ($counts?->returned ?? 0)];
    }
}
