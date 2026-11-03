<?php

namespace App\Domain\Cajas\Queries;

use Illuminate\Support\Facades\DB;

/** Internal read model; the caller must authorize the active session first. */
class CashBoxClosingCounterReferenceQuery
{
    public function forBox(int $boxId): array
    {
        $month = now('America/La_Paz')->format('Y-m');
        $references = [];
        foreach (['general' => 'reading', 'fixed' => 'fixed_reading'] as $key => $column) {
            $last = DB::table('cash_box_counter_readings')->where('cash_box_id', $boxId)
                ->whereNull('voided_at')->whereNotNull($column)->where('month', '<=', $month)
                ->orderByDesc('month')->orderByDesc('id')->first(['month', $column]);
            $references[$key] = ['value' => $last ? ($last->month === $month ? (int) $last->{$column} : 0) : null,
                'monthly_reset' => $last !== null && $last->month !== $month];
        }

        return ['month' => $month, 'counters' => $references];
    }
}
