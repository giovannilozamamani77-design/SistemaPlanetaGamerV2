<?php

namespace App\Domain\ControlHorario\Queries;

use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/** Internal sources captured while controls were active; never backfill paused sessions. */
class CashBoxContinuityCandidatesQuery
{
    public function forDay(ScheduledWorkday $day): Collection
    {
        $date = CarbonImmutable::parse($day->work_date, 'America/La_Paz')->startOfDay()->utc();

        return CashBoxScheduleEvent::where('cash_box_schedule_events.user_id', $day->user_id)->where('kind', 'OPENING')
            ->join('cash_box_sessions as s', 's.id', '=', 'cash_box_schedule_events.cash_box_session_id')
            ->where('s.user_id', $day->user_id)->where('s.opened_at', '>=', $date)->where('s.opened_at', '<=', $day->starts_at)
            ->where(fn ($q) => $q->whereNull('s.closed_at')->orWhere('s.closed_at', '>', $day->starts_at))
            ->orderBy('cash_box_schedule_events.id')->get(['cash_box_schedule_events.*']);
    }

    public function isAdjacent(CashBoxScheduleEvent $opening, ScheduledWorkday $day): bool
    {
        if ($opening->status !== 'DONE' || ! $opening->scheduled_workday_id || $opening->scheduled_workday_id === $day->id) {
            return false;
        }
        $prior = ScheduledWorkday::whereKey($opening->scheduled_workday_id)->where('user_id', $day->user_id)->first();

        return $prior && $prior->work_date === $day->work_date && $prior->ends_at->equalTo($day->starts_at);
    }
}
