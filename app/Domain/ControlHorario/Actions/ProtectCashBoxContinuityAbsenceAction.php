<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Queries\CashBoxContinuityCandidatesQuery;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProtectCashBoxContinuityAbsenceAction
{
    /** Presence requires evidence; an unresolved continuous session must not become an automatic absence. */
    public function shouldDefer(ScheduledWorkday $day): bool
    {
        try {
            $candidates = app(CashBoxContinuityCandidatesQuery::class);
            foreach ($candidates->forDay($day) as $opening) {
                app(ProcessCashBoxScheduleEventAction::class)->safely($opening->id);
                $opening->refresh();
                $unresolved = in_array($opening->status, ['PENDING', 'FAILED'], true);
                if (! $unresolved && ! $candidates->isAdjacent($opening, $day)) {
                    continue;
                }
                // Recover from a crash after a real operation but before its continuity callback.
                $operation = CashBoxOperation::where('cash_box_session_id', $opening->cash_box_session_id)->where('user_id', $day->user_id)
                    ->where('created_at', '>=', $day->starts_at)->where('created_at', '<=', $day->ends_at)->orderBy('id')->first();
                if ($operation) {
                    app(CaptureCashBoxContinuityAction::class)->fromOperation($operation);
                }
                if ($day->attendance()->exists()) {
                    return true;
                }
                $pendingSource = $opening;
            }
            if (isset($pendingSource)) {
                $key = 'continuity-review:'.$day->id;
                if (! DB::table('time_history_events')->where('event_key', $key)->exists()) {
                    app(TimeHistoryAudit::class)->record($day->user_id, null, 'CONTINUIDAD_POR_VERIFICAR', $key,
                        ['workday_id' => $day->id, 'session_id' => $pendingSource->cash_box_session_id, 'reason' => 'No confirmar falta ni presencia sin resolver la continuidad.']);
                }

                return true;
            }

            return false;
        } catch (Throwable $error) {
            report($error);

            return true;
        }
    }
}
