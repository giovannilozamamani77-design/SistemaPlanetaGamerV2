<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxEarlyRequest;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\CashBoxWorkdayContinuity;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Internal durable bridge. No HTTP endpoint and no financial balance writes. */
class ProcessCashBoxScheduleEventAction
{
    public function safely(int $eventId): void
    {
        if (! app(ScheduleConfiguration::class)->enabled()) {
            return;
        }
        try {
            $this->execute($eventId);
        } catch (Throwable $exception) {
            // A failed domain transaction has rolled back; the source remains retryable.
            try {
                CashBoxScheduleEvent::whereKey($eventId)->whereNotIn('status', ['DONE', 'SKIPPED'])
                    ->update(['status' => 'FAILED', 'error_code' => 'SCHEDULE_PROCESSING_FAILED']);
            } catch (Throwable $storageFailure) {
                report($storageFailure);
            }
            report($exception);
        }
    }

    public function execute(int $eventId): void
    {
        if (! app(ScheduleConfiguration::class)->enabled()) {
            return;
        }
        $source = CashBoxScheduleEvent::findOrFail($eventId);
        if (in_array($source->status, ['DONE', 'SKIPPED'], true)) {
            return;
        }
        if ($source->kind === 'OPENING' && ! app(ScheduleConfiguration::class)->allows(CashBoxSession::findOrFail($source->cash_box_session_id)->cash_box_id)) {
            return;
        }
        if ($source->kind === 'WORK') {
            $access = CashBoxAccess::findOrFail($source->access_id);
            $session = CashBoxSession::findOrFail($access->cash_box_session_id);
            if (! app(ScheduleConfiguration::class)->allows($session->cash_box_id)) {
                return;
            }
            $opening = CashBoxScheduleEvent::where('source_key', 'opening:'.$session->id)->firstOrFail();
            $this->execute($opening->id);
        }
        if ($source->kind === 'OPENING') {
            $access = CashBoxAccess::findOrFail($source->access_id);
            $pin = CashBoxScheduleEvent::where('source_key', 'pin:'.$source->user_id.':'.$access->request_key)->where('pin_audit_id', $access->pin_audit_id)->firstOrFail();
            $this->execute($pin->id);
            // Process older confirmed openings first, even if the retry worker saw this one first.
            $dateStart = $source->occurred_at->setTimezone('America/La_Paz')->startOfDay()->utc();
            foreach (CashBoxScheduleEvent::where('user_id', $source->user_id)->where('kind', 'OPENING')
                ->where('occurred_at', '>=', $dateStart)->where('occurred_at', '<', $dateStart->addDay())
                ->where('id', '<', $source->id)->whereNotIn('status', ['DONE', 'SKIPPED'])->orderBy('id')->pluck('id') as $olderId) {
                $this->execute($olderId);
            }
        }
        if ($source->kind === 'OPENING' && $pin->fresh()->scheduled_workday_id) {
            foreach (CashBoxWorkdayContinuity::where('user_id', $source->user_id)->where('scheduled_workday_id', $pin->fresh()->scheduled_workday_id)
                ->where('cash_box_session_id', '!=', $source->cash_box_session_id)->where('status', '!=', 'DONE')->orderBy('id')->pluck('id') as $linkId) {
                app(ProcessCashBoxContinuityAction::class)->execute($linkId);
            }
        }
        CashBoxScheduleEvent::whereKey($eventId)->increment('attempts');
        DB::transaction(function () use ($eventId, $source): void {
            $user = User::whereKey($source->user_id)->lockForUpdate()->firstOrFail();
            $event = CashBoxScheduleEvent::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if (in_array($event->status, ['DONE', 'SKIPPED'], true)) {
                return;
            }
            if ($event->kind === 'PIN') {
                if ($user->can('controlhorario.asistencia.registrar')) {
                    app(EnsureRecurringWorkdaysAction::class)->execute($user, $event->occurred_at->toImmutable());
                }
                $day = ScheduledWorkday::where('user_id', $user->id)
                    ->where('work_date', $event->occurred_at->setTimezone('America/La_Paz')->toDateString())
                    ->where('ends_at', '>', $event->occurred_at)->orderBy('starts_at')->orderBy('id')->first();
                if ($day && $user->can('controlhorario.asistencia.registrar')) {
                    if (app(ScheduleConfiguration::class)->controlsWorkday($day)) {
                        app(RegisterLinkedAttendanceAction::class)->execute($user, $day, $event->pin_audit_id,
                            'cash-box-pin:'.$event->pin_audit_id, $event->occurred_at);
                    }
                } else {
                    $day = null;
                }
            } elseif ($event->kind === 'OPENING') {
                $access = CashBoxAccess::findOrFail($event->access_id);
                $session = CashBoxSession::findOrFail($event->cash_box_session_id);
                if ($access->user_id !== $user->id || $session->user_id !== $user->id || $access->cash_box_session_id !== $session->id) {
                    throw new RuntimeException('Invalid internal schedule source');
                }
                $pin = CashBoxScheduleEvent::where('source_key', 'pin:'.$user->id.':'.$access->request_key)->where('pin_audit_id', $access->pin_audit_id)->firstOrFail();
                $day = $pin->scheduled_workday_id ? ScheduledWorkday::findOrFail($pin->scheduled_workday_id) : null;
                if ($day && $day->work_date !== $event->occurred_at->setTimezone('America/La_Paz')->toDateString()) {
                    $day = null;
                }
                $access->update(['scheduled_workday_id' => $day?->id]);
                $session->update(['scheduled_workday_id' => $day?->id]);
                if ($day && $event->family === 'BCP') {
                    app(DetectTimeIncidentAction::class)->opening($user, $day, $event->occurred_at, 'cash-box-session:'.$session->id);
                }
            } elseif ($event->kind === 'WORK') {
                $day = app(ApplyCashBoxEarlyWorkAction::class)->execute($user, $event);
            } elseif ($event->kind === 'CLOSING') {
                $closeId = (int) substr($event->source_key, strlen('closing:'));
                $close = CashBoxClose::findOrFail($closeId);
                $session = CashBoxSession::findOrFail($close->cash_box_session_id);
                $access = CashBoxAccess::findOrFail($event->access_id);
                if ($close->user_id !== $user->id || $access->cash_box_session_id !== $session->id || $access->user_id !== $user->id || $access->pin_audit_id !== $event->pin_audit_id) {
                    throw new RuntimeException('Invalid closing schedule source');
                }
                if ($close->cancelled_at) {
                    $event->update(['status' => 'SKIPPED', 'processed_at' => now('UTC')]);

                    return;
                }
                if (! app(ScheduleConfiguration::class)->allows($session->cash_box_id)) {
                    return;
                }
                $day = ScheduledWorkday::whereKey($event->scheduled_workday_id)->where('user_id', $user->id)->firstOrFail();
                $approval = CashBoxEarlyRequest::where('cash_box_session_id', $session->id)->where('period', $day->period)->where('status', 'APROBADA')->firstOrFail();
                app(DetectTimeIncidentAction::class)->earlyDeparture($user, $day, $event->occurred_at, 'cash-box-early:'.$approval->id);
            } else {
                throw new RuntimeException('Unknown schedule event kind');
            }
            $event->update(['scheduled_workday_id' => $day?->id, 'status' => $day ? 'DONE' : 'SKIPPED',
                'error_code' => null, 'processed_at' => now('UTC')]);
        });
    }
}
