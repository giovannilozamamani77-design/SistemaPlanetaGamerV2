<?php

namespace App\Domain\Envios\Queries;

use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use Illuminate\Support\Facades\DB;

class InternationalCustody implements ReservedCashBoxFunds
{
    public function forBox(int $boxId): array
    {
        return DB::table('international_transfers')->where('cash_box_id', $boxId)->where('local_held_cents', '>', 0)
            ->groupBy('component')->selectRaw('component, SUM(local_held_cents) AS total')->pluck('total', 'component')->map(fn ($amount): int => (int) $amount)->all();
    }
}
