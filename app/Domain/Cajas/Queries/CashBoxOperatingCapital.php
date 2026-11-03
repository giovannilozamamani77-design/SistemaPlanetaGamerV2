<?php

namespace App\Domain\Cajas\Queries;

use Illuminate\Support\Facades\DB;

class CashBoxOperatingCapital
{
    public function funds(int $boxId): array
    {
        return DB::table('cash_box_temporary_funds')->where('cash_box_id', $boxId)->where('remaining_cents', '>', 0)->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
    }

    public function amount(int $boxId, int $base): int
    {
        return $base + (int) DB::table('cash_box_temporary_funds')->where('cash_box_id', $boxId)->selectRaw("COALESCE(SUM(CASE WHEN kind = 'DEPOSITO' THEN remaining_cents ELSE -remaining_cents END),0) as delta")->value('delta');
    }
}
