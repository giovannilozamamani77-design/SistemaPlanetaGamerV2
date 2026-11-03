<?php

namespace App\Domain\Cajas\Queries;

use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClosingRestorationHistoryQuery
{
    public function execute(User $actor, int $closeId): array
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('cajas.operar');
        $close = CashBoxClose::findOrFail($closeId);
        if ($close->user_id !== $user->id) {
            app(AdministrativeReview::class)->authorize($user, 'cajas.liberar');
        }

        return DB::table('cash_box_capital_restorations as r')->join('users as u', 'u.id', '=', 'r.actor_user_id')
            ->where('r.cash_box_close_id', $closeId)->orderBy('r.id')->get(['r.component', 'r.amount_cents', 'r.receipt', 'r.created_at', 'u.name as administrator'])->all();
    }
}
