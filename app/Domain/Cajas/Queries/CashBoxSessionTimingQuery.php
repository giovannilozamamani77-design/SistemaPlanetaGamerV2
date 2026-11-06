<?php

namespace App\Domain\Cajas\Queries;

use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Services\CashBoxScheduleSafety;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\CashBoxWorkdayContinuity;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;

/** Read-only projection; the workspace authorizes the session before calling. */
class CashBoxSessionTimingQuery
{
    public function forSession(?CashBoxSession $session): array
    {
        $empty = ['status' => 'NO_SCHEDULE', 'scheduled_start' => null, 'scheduled_end' => null,
            'delay_minutes' => null, 'reference_opened_at' => null, 'reference_is_this_box' => false, 'continuity' => false];

        return app(CashBoxScheduleSafety::class)->attempt(fn (): array => $this->evaluateTiming($session, $empty), array_replace($empty, ['status' => 'PENDING']));
    }

    private function evaluateTiming(?CashBoxSession $session, array $empty): array
    {
        if (! app(ScheduleConfiguration::class)->enabled()) {
            return array_replace($empty, ['status' => 'PAUSED']);
        }
        if ($session && ! app(ScheduleConfiguration::class)->allows($session->cash_box_id)) {
            return array_replace($empty, ['status' => 'DISABLED']);
        }
        if ($session && CashBoxScheduleEvent::where('cash_box_session_id', $session->id)->whereIn('status', ['PENDING', 'FAILED'])->exists()) {
            return array_replace($empty, ['status' => 'PENDING']);
        }
        $continuity = $session ? CashBoxWorkdayContinuity::where('cash_box_session_id', $session->id)
            ->where('user_id', $session->user_id)->orderByDesc('effective_at')->first() : null;
        if ($continuity && $continuity->status !== 'DONE') {
            return array_replace($empty, ['status' => 'PENDING']);
        }
        $dayId = $continuity?->scheduled_workday_id ?? $session?->scheduled_workday_id;
        if (! $dayId) {
            return $empty;
        }
        $day = ScheduledWorkday::whereKey($dayId)->where('user_id', $session->user_id)
            ->first(['id', 'period', 'starts_at', 'ends_at']);
        if (! $day) {
            return $empty;
        }
        if (! app(ScheduleConfiguration::class)->controlsWorkday($day)) {
            return array_replace($empty, ['status' => 'TURN_DISABLED']);
        }
        $incident = TimeIncident::where('scheduled_workday_id', $day->id)->where('user_id', $session->user_id)
            ->where('source_key', 'opening:'.$day->id)->where('kind', 'RETRASO')
            ->first(['real_minutes', 'observed_at', 'calculation']);

        return array_replace($empty, [
            'status' => $incident ? 'RECORDED' : 'NOT_EVALUATED',
            'continuity' => $continuity !== null,
            'scheduled_start' => $day->starts_at->setTimezone('America/La_Paz')->format('d/m/Y H:i'),
            'scheduled_end' => $day->ends_at->setTimezone('America/La_Paz')->format('H:i'),
            'delay_minutes' => $incident?->real_minutes,
            'reference_opened_at' => $incident?->observed_at?->setTimezone('America/La_Paz')->format('d/m/Y H:i'),
            'reference_is_this_box' => $incident && ($incident->calculation['reference'] ?? null) === 'cash-box-session:'.$session->id,
        ]);
    }
}
