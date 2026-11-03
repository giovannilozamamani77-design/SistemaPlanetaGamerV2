<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Domain\ControlHorario\Queries\ShiftCoverageContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/** Internal: shared schedule-event transaction holds the owner and source locks. */
class ApplyCashBoxEarlyWorkAction
{
    public function execute(User $actor, CashBoxScheduleEvent $event): ?ScheduledWorkday
    {
        Gate::forUser($actor)->authorize('cajas.operar');
        if (! preg_match('/\Awork-(operation|close):([1-9][0-9]*)\z/', $event->source_key, $match)) {
            throw new RuntimeException('Invalid early work source');
        }
        $fact = $match[1] === 'operation' ? CashBoxOperation::findOrFail((int) $match[2]) : CashBoxClose::findOrFail((int) $match[2]);
        $session = CashBoxSession::findOrFail($fact->cash_box_session_id);
        $opening = CashBoxScheduleEvent::where('source_key', 'opening:'.$session->id)->firstOrFail();
        $access = CashBoxAccess::findOrFail($event->access_id);
        if ($event->user_id !== $actor->id || $fact->user_id !== $actor->id || $session->user_id !== $actor->id
            || $opening->user_id !== $actor->id || $opening->access_id !== $access->id || $access->cash_box_session_id !== $session->id
            || $access->user_id !== $actor->id || $access->pin_audit_id !== $event->pin_audit_id || $opening->pin_audit_id !== $event->pin_audit_id
            || ! $event->occurred_at->equalTo($fact->created_at) || $event->occurred_at > now('UTC')
            || $event->occurred_at < $session->opened_at || ($session->closed_at && $event->occurred_at > $session->closed_at)) {
            throw new RuntimeException('Invalid early work evidence');
        }
        if ($match[1] === 'close' && $fact->cancelled_at) {
            return null;
        }
        $day = $opening->scheduled_workday_id ? ScheduledWorkday::whereKey($opening->scheduled_workday_id)->where('user_id', $actor->id)->firstOrFail() : null;
        $coverage = $day ? app(ShiftCoverageContext::class)->forWorkday($day) : null;
        $day = $coverage?->creditWorkday ?? $day;
        if (! $day || ! app(ScheduleConfiguration::class)->controlsWorkday($day) || $session->opened_at >= $day->starts_at) {
            return null;
        }
        app(ApplyTimeCreditAction::class)->execute($day, CarbonImmutable::instance($session->opened_at), $event->occurred_at,
            'cash-box:'.$event->source_key, $coverage?->credit_workday_id ? $coverage : null);

        return $day;
    }
}
