<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Domain\ControlHorario\Queries\ShiftCoverageContext;
use Carbon\CarbonImmutable;
use Throwable;

class CaptureCashBoxEarlyWorkAction
{
    /** Internal: financial caller has authorized ownership and holds its transaction locks. */
    public function operation(CashBoxOperation $operation, CashBoxSession $session): void
    {
        $this->record($session, 'work-operation:'.$operation->id, CarbonImmutable::instance($operation->created_at));
    }

    /** Internal: capture the effective closing, never its preview or request. */
    public function closing(CashBoxClose $close, CashBoxSession $session): void
    {
        $this->record($session, 'work-close:'.$close->id, CarbonImmutable::instance($close->created_at));
    }

    private function record(CashBoxSession $session, string $key, CarbonImmutable $instant): void
    {
        if (! app(ScheduleConfiguration::class)->allows($session->cash_box_id) || $instant <= $session->opened_at) {
            return;
        }
        $opening = CashBoxScheduleEvent::where('source_key', 'opening:'.$session->id)->where('user_id', $session->user_id)->first();
        if (! $opening || $opening->status === 'SKIPPED') {
            return;
        }
        $day = $opening->scheduled_workday_id ? ScheduledWorkday::findOrFail($opening->scheduled_workday_id) : null;
        if ($day) {
            $day = app(ShiftCoverageContext::class)->forWorkday($day)?->creditWorkday ?? $day;
        }
        if ($day && ($session->opened_at >= $day->starts_at || ! app(ScheduleConfiguration::class)->controlsWorkday($day))) {
            return;
        }
        // One completed endpoint is enough; later ordinary operations need no new sources.
        if ($day && $instant >= $day->starts_at && CashBoxScheduleEvent::where('user_id', $session->user_id)->where('kind', 'WORK')
            ->where('access_id', $opening->access_id)->where('occurred_at', '>=', $day->starts_at)->exists()) {
            return;
        }
        CashBoxScheduleEvent::firstOrCreate(['source_key' => $key], ['kind' => 'WORK', 'user_id' => $session->user_id,
            'access_id' => $opening->access_id, 'pin_audit_id' => $opening->pin_audit_id,
            'scheduled_workday_id' => $day?->id, 'occurred_at' => $instant]);
    }

    /** Run after financial commit; persisted sources survive callback or time-processing failure. */
    public function safelyForSession(int $sessionId): void
    {
        if (! app(ScheduleConfiguration::class)->enabled()) {
            return;
        }
        try {
            $opening = CashBoxScheduleEvent::where('source_key', 'opening:'.$sessionId)->first();
            if (! $opening) {
                return;
            }
            foreach (CashBoxScheduleEvent::where('user_id', $opening->user_id)->where('kind', 'WORK')->where('access_id', $opening->access_id)
                ->whereIn('status', ['PENDING', 'FAILED'])->orderBy('id')->limit(20)->pluck('id') as $id) {
                app(ProcessCashBoxScheduleEventAction::class)->safely($id);
            }
        } catch (Throwable $error) {
            report($error);
        }
    }
}
