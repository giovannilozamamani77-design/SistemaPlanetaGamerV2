<?php

namespace App\Domain\ControlHorario\Queries;

use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Models\CashBoxWorkdayContinuity;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\ShiftCoverage;

class ShiftCoverageContext
{
    public function forWorkday(ScheduledWorkday $day): ?ShiftCoverage
    {
        return ShiftCoverage::where('workday_id', $day->id)->where('user_id', $day->user_id)->with('creditWorkday')->first();
    }

    /** A real normal closing, not a request, permits finishing the optional morning coverage. */
    public function optionalCompletion(ScheduledWorkday $day): ?CashBoxClose
    {
        $coverage = ShiftCoverage::where('credit_workday_id', $day->id)->where('user_id', $day->user_id)->with('workday')->first();
        if (! $coverage) {
            return null;
        }
        $sessions = CashBoxSession::where('user_id', $day->user_id)->where('scheduled_workday_id', $coverage->workday_id);
        if ((clone $sessions)->whereNull('closed_at')->exists()
            || CashBoxWorkdayContinuity::whereIn('cash_box_session_id', (clone $sessions)->select('id'))->where('scheduled_workday_id', $day->id)->exists()) {
            return null;
        }
        $window = app(ScheduleConfiguration::class)->shifts(app(PersonnelRules::class)->at($coverage->workday->starts_at))[$coverage->workday->period]['closing_minutes'];

        return CashBoxClose::where('user_id', $day->user_id)->whereIn('cash_box_session_id', (clone $sessions)->select('id'))
            ->where('period', $coverage->workday->period)->whereNull('cancelled_at')
            ->where('created_at', '>=', $coverage->ends_at->subMinutes($window))->latest('id')->first();
    }
}
