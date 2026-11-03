<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\CashBoxWorkdayContinuity;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Queries\CashBoxContinuityCandidatesQuery;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProcessCashBoxContinuityAction
{
    public function safely(int $id): void
    {
        if (! app(ScheduleConfiguration::class)->enabled()) {
            return;
        }
        try {
            $this->execute($id);
        } catch (Throwable $error) {
            try {
                CashBoxWorkdayContinuity::whereKey($id)->where('status', '!=', 'DONE')->update(['status' => 'FAILED', 'error_code' => 'CONTINUITY_PROCESSING_FAILED']);
            } catch (Throwable $storageError) {
                report($storageError);
            }
            report($error);
        }
    }

    public function execute(int $id): void
    {
        if (! app(ScheduleConfiguration::class)->enabled()) {
            return;
        }
        $source = CashBoxWorkdayContinuity::findOrFail($id);
        if (! app(ScheduleConfiguration::class)->allows(CashBoxSession::findOrFail($source->cash_box_session_id)->cash_box_id)) {
            return;
        }
        if ($source->status === 'DONE') {
            return;
        }
        if (! app(ScheduleConfiguration::class)->controlsWorkday(ScheduledWorkday::findOrFail($source->scheduled_workday_id))) {
            return;
        }
        app(ProcessCashBoxScheduleEventAction::class)->execute($source->opening_event_id);
        CashBoxWorkdayContinuity::whereKey($id)->increment('attempts');
        DB::transaction(function () use ($id, $source): void {
            $user = User::whereKey($source->user_id)->lockForUpdate()->firstOrFail();
            $link = CashBoxWorkdayContinuity::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($link->status === 'DONE') {
                return;
            }
            $day = ScheduledWorkday::whereKey($link->scheduled_workday_id)->lockForUpdate()->firstOrFail();
            $opening = CashBoxScheduleEvent::findOrFail($link->opening_event_id);
            $session = CashBoxSession::findOrFail($link->cash_box_session_id);
            if ($day->user_id !== $user->id || $session->user_id !== $user->id || $opening->user_id !== $user->id
                || $opening->cash_box_session_id !== $session->id || ! app(CashBoxContinuityCandidatesQuery::class)->isAdjacent($opening, $day)
                || ! $link->effective_at->equalTo($day->starts_at) || $session->opened_at > $day->starts_at
                || $link->observed_at < $day->starts_at || $link->observed_at > $day->ends_at || $link->observed_at > now('UTC')
                || ($session->closed_at && $session->closed_at < $link->observed_at)) {
                throw new RuntimeException('Invalid internal continuity source');
            }
            if ($link->evidence_kind === 'OPERATION') {
                $operation = CashBoxOperation::findOrFail($link->operation_id);
                if ($operation->user_id !== $user->id || $operation->cash_box_session_id !== $session->id || ! $operation->created_at->equalTo($link->observed_at)) {
                    throw new RuntimeException('Invalid persisted continuity operation');
                }
            } elseif ($link->evidence_kind !== 'RESUME') {
                throw new RuntimeException('Unknown continuity evidence');
            }
            app(RegisterLinkedAttendanceAction::class)->execute($user, $day, $opening->pin_audit_id,
                'cash-box-continuity:'.$link->id, $link->observed_at);
            if ($opening->family === 'BCP') {
                app(DetectTimeIncidentAction::class)->opening($user, $day, $link->effective_at, 'cash-box-session:'.$session->id);
            }
            // Attendance evidence is not a fabricated second PIN or financial opening.
            $day->attendance()->update(['status' => 'PRESENTE']);
            app(TimeHistoryAudit::class)->record($user->id, $user->id, 'CONTINUIDAD_DE_JORNADA', 'continuity:'.$session->id.':'.$day->id,
                ['continuity_id' => $link->id, 'session_id' => $session->id, 'workday_id' => $day->id,
                    'pin_audit_id' => $opening->pin_audit_id, 'effective_at' => $link->effective_at->toIso8601String(),
                    'observed_at' => $link->observed_at->toIso8601String(), 'evidence_kind' => $link->evidence_kind]);
            $link->update(['status' => 'DONE', 'error_code' => null, 'processed_at' => now('UTC')]);
        });
    }
}
