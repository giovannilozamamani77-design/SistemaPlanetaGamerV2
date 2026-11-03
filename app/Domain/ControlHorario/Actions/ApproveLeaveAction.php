<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Domain\ControlHorario\Models\MonthlyLeave;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveLeaveAction
{
    public function execute(User $actor, LeaveRequest $target, bool $approved, string $reason, bool $exception = false): LeaveRequest
    {
        app(AdministrativeReview::class)->authorize($actor, 'controlhorario.revisar', $target->user_id, $exception, $reason);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Escribe la decisión administrativa.']);
        }

        return DB::transaction(function () use ($actor, $target, $approved, $reason): LeaveRequest {
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $request = LeaveRequest::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'PENDIENTE') {
                return $request;
            }
            $leave = MonthlyLeave::whereKey($request->monthly_leave_id)->lockForUpdate()->firstOrFail();
            if ($approved) {
                if ($leave->status !== 'DISPONIBLE' || ($request->kind !== 'AUSENCIA_FUTURA' && $leave->month !== now('America/La_Paz')->format('Y-m'))) {
                    throw ValidationException::withMessages(['reason' => 'El día libre no está disponible en ese mes.']);
                }
                if ($request->kind === 'AUSENCIA_FUTURA') {
                    if ($request->scheduledWorkday->starts_at <= now()) {
                        throw ValidationException::withMessages(['reason' => 'La jornada ya comenzó; revisa una falta o permiso histórico con su flujo.']);
                    }
                } else {
                    $query = TimeIncident::where('user_id', $request->user_id)->where('status', 'CONFIRMADA')->where('pending_minutes', '>', 0);
                    if ($request->kind === 'COMPENSAR_FALTA') {
                        $query->whereKey($request->time_incident_id)->whereIn('kind', ['FALTA', 'FALTA_PARCIAL'])->where('pending_minutes', '>', 0);
                    } else {
                        $query->where('kind', 'RETRASO');
                    }
                    $cases = $query->orderBy('id')->lockForUpdate()->get();
                    if ($cases->sum('pending_minutes') < 1) {
                        throw ValidationException::withMessages(['reason' => 'Ya no quedan minutos elegibles pendientes.']);
                    }
                    $remaining = $request->kind === 'COMPENSAR_FALTA' ? (int) $cases->sum('pending_minutes') : min(360, (int) $cases->sum('pending_minutes'));
                    foreach ($cases as $case) {
                        $used = min($remaining, $case->pending_minutes);
                        if ($used === 0) {
                            break;
                        }
                        $bucket = $case->kind === 'RETRASO' ? 'RETRASO' : 'FALTA_REAL';
                        TimeMovement::create(['user_id' => $case->user_id, 'time_incident_id' => $case->id, 'actor_user_id' => $actor->id, 'bucket' => $bucket, 'delta_minutes' => -$used, 'event_key' => 'leave:'.$request->id.':case:'.$case->id, 'metadata' => ['leave_request_id' => $request->id, 'minutes' => $used, 'reason' => $reason], 'created_at' => now()]);
                        $case->pending_minutes -= $used;
                        $case->save();
                        $remaining -= $used;
                    }
                }
                $leave->update(['status' => 'USADO', 'approved_by' => $actor->id, 'used_at' => now()]);
            }
            $request->update(['status' => $approved ? 'APROBADA' : 'RECHAZADA', 'decided_by' => $actor->id, 'decision_reason' => $reason, 'decided_at' => now()]);
            app(TimeHistoryAudit::class)->record($request->user_id, $actor->id, 'PERMISO_DECIDIDO', 'leave:'.$request->id.':decision', ['approved' => $approved, 'reason' => $reason, 'month' => $leave->month]);

            return $request;
        });
    }
}
