<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Domain\ControlHorario\Models\MonthlyLeave;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RequestLeaveAction
{
    /** @param array<string,mixed> $data */
    public function execute(User $actor, array $data, ?User $target = null): LeaveRequest
    {
        $owner = $target ? User::findOrFail($target->id) : User::findOrFail($actor->id);
        if ($owner->id !== $actor->id) {
            app(AdministrativeReview::class)->authorize($actor, 'controlhorario.revisar');
        } else {
            Gate::forUser(User::findOrFail($actor->id))->authorize('controlhorario.solicitar');
        }
        $v = Validator::make($data, [
            'kind' => ['required', Rule::in(['AUSENCIA_FUTURA', 'COMPENSAR_RETRASOS', 'COMPENSAR_FALTA'])],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'request_key' => ['required', 'uuid'],
            'workday_id' => ['nullable', 'integer'],
            'work_date' => ['nullable', 'date_format:Y-m-d'], 'period' => ['nullable', Rule::in(['manana', 'tarde'])],
            'incident_id' => ['nullable', 'integer'],
        ])->validate();

        return DB::transaction(function () use ($actor, $owner, $v): LeaveRequest {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            if ($existing = LeaveRequest::where('request_key', $v['request_key'])->first()) {
                if ($existing->user_id !== $owner->id) {
                    throw new AuthorizationException;
                }
                if ($existing->kind !== $v['kind'] || $existing->reason !== $v['reason']
                    || (! empty($v['work_date']) && $existing->scheduledWorkday?->work_date !== $v['work_date'])
                    || (! empty($v['period']) && $existing->scheduledWorkday?->period !== $v['period'])) {
                    throw ValidationException::withMessages(['request_key' => 'Esta solicitud ya fue registrada con otros datos.']);
                }

                return $existing;
            }
            $day = null;
            $case = null;
            $month = now('America/La_Paz')->format('Y-m');
            if ($v['kind'] === 'AUSENCIA_FUTURA') {
                $day = ! empty($v['workday_id']) ? ScheduledWorkday::where('user_id', $owner->id)->whereKey($v['workday_id'])->firstOrFail()
                    : app(PrepareDatedWorkdayAction::class)->execute($actor, $owner, $v['work_date'] ?? '', $v['period'] ?? '');
                if ($day->starts_at <= now()) {
                    throw ValidationException::withMessages(['workday_id' => 'Selecciona una jornada futura.']);
                }
                $month = substr($day->work_date, 0, 7);
                if ($previous = LeaveRequest::where('user_id', $owner->id)->where('scheduled_workday_id', $day->id)->whereIn('status', ['PENDIENTE', 'APROBADA'])->first()) {
                    return $previous;
                }
            } elseif ($v['kind'] === 'COMPENSAR_FALTA') {
                $case = TimeIncident::where('user_id', $owner->id)->whereKey($v['incident_id'] ?? 0)->whereIn('kind', ['FALTA', 'FALTA_PARCIAL'])->where('status', 'CONFIRMADA')->where('pending_minutes', '>', 0)->firstOrFail();
            } elseif ((int) TimeIncident::where('user_id', $owner->id)->where('kind', 'RETRASO')->where('status', 'CONFIRMADA')->sum('pending_minutes') < 1) {
                throw ValidationException::withMessages(['kind' => 'No hay minutos de retraso confirmados pendientes.']);
            }
            $leave = MonthlyLeave::firstOrCreate(['user_id' => $owner->id, 'month' => $month]);
            if ($leave->status !== 'DISPONIBLE') {
                throw ValidationException::withMessages(['kind' => 'El día libre del mes ya fue usado.']);
            }
            $request = LeaveRequest::create(['user_id' => $owner->id, 'monthly_leave_id' => $leave->id, 'scheduled_workday_id' => $day?->id, 'time_incident_id' => $case?->id, 'kind' => $v['kind'], 'status' => 'PENDIENTE', 'request_key' => $v['request_key'], 'reason' => $v['reason']]);
            app(TimeHistoryAudit::class)->record($owner->id, $actor->id, 'PERMISO_SOLICITADO', 'leave:'.$request->id.':request', ['kind' => $request->kind, 'month' => $month, 'reason' => $request->reason]);

            return $request;
        });
    }
}
