<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Data\TimeReasonCodes;
use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Domain\ControlHorario\Models\MonthlyLeave;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReviewTimeIncidentAction
{
    public function __construct(private ReconcileTimeCreditAction $credit, private TimeHistoryAudit $audit) {}

    public function execute(User $actor, TimeIncident $target, string $decision, string $reason, bool $exception = false): TimeIncident
    {
        app(AdministrativeReview::class)->authorize($actor, 'controlhorario.revisar', $target->user_id, $exception, $reason);
        if (! in_array($decision, ['CONFIRMAR', 'JUSTIFICAR', 'INVALIDAR', 'PERDONAR'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Selecciona decisión y motivo.']);
        }

        return DB::transaction(function () use ($actor, $target, $decision, $reason): TimeIncident {
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $case = TimeIncident::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if (in_array($case->status, ['JUSTIFICADA', 'INVALIDADA', 'PERDONADA', 'SIN_RETRASO', 'PERMISO_AUTORIZADO'], true)) {
                return $case;
            }
            if ($decision === 'CONFIRMAR' && $case->status === 'CONFIRMADA') {
                return $case;
            }
            if ($decision === 'PERDONAR') {
                $remaining = $case->pending_minutes;
                if ($remaining > 0) {
                    TimeMovement::create(['user_id' => $case->user_id, 'time_incident_id' => $case->id, 'actor_user_id' => $actor->id, 'bucket' => $case->kind === 'RETRASO' ? 'RETRASO' : 'FALTA_REAL', 'delta_minutes' => -$remaining, 'event_key' => 'forgive-time:'.$case->id, 'metadata' => ['kind' => 'TIEMPO_PERDONADO', 'reason' => $reason], 'created_at' => now()]);
                }
                $case->update(['status' => 'PERDONADA', 'pending_minutes' => 0, 'decision_reason' => $reason]);
                $this->audit->record($case->user_id, $actor->id, 'TIEMPO_PERDONADO', 'forgive-time:'.$case->id, ['minutes' => $remaining, 'reason' => $reason]);

                return $case->refresh();
            }
            $confirmed = TimeMovement::where('event_key', 'confirm:'.$case->id)->exists() || $case->kind === 'FALTA_PARCIAL';
            if ($decision === 'CONFIRMAR' && $confirmed) {
                $case->update(['status' => 'CONFIRMADA', 'decision_reason' => $reason]);
                $key = 'ratify:'.$case->id.':'.Str::uuid();
                $this->credit->execute($case->user_id, $key);
                $this->audit->record($case->user_id, $actor->id, 'INCIDENCIA_RATIFICADA', $key, ['reason' => $reason, 'incident_id' => $case->id]);

                return $case->refresh();
            }
            if ($decision === 'JUSTIFICAR' && $case->kind === 'RETRASO' && ! TimeReasonCodes::justifiable($case->reason_code ?? '')) {
                throw ValidationException::withMessages(['reason' => 'Motivo personal no anula retraso; invalidar exige error demostrado.']);
            }
            $before = $case->status;
            if ($decision !== 'CONFIRMAR' && $confirmed) {
                $this->reverseSettlements($case, $actor, $reason);
            }
            $case->status = match ($decision) {
                'CONFIRMAR' => 'CONFIRMADA','JUSTIFICAR' => 'JUSTIFICADA',default => 'INVALIDADA'
            };
            $case->decision_reason = $reason;
            $case->pending_minutes = $decision === 'CONFIRMAR' ? $case->nominal_minutes : 0;
            $case->save();
            if ($decision === 'CONFIRMAR') {
                TimeMovement::firstOrCreate(['event_key' => 'confirm:'.$case->id], ['user_id' => $case->user_id, 'time_incident_id' => $case->id, 'actor_user_id' => $actor->id, 'bucket' => $case->kind === 'RETRASO' ? 'RETRASO' : 'FALTA_REAL', 'delta_minutes' => $case->nominal_minutes, 'metadata' => ['decision' => $reason], 'created_at' => now()]);
            }
            $this->credit->execute($case->user_id, 'review:'.$case->id.':'.$decision);
            $this->audit->record($case->user_id, $actor->id, 'INCIDENCIA_'.$decision, 'review:'.$case->id.':'.$decision, ['incident_id' => $case->id, 'reason' => $reason, 'before' => $before]);

            return $case->refresh();
        });
    }

    private function reverseSettlements(TimeIncident $case, User $actor, string $reason): void
    {
        $all = TimeMovement::where('time_incident_id', $case->id)->get();
        $keys = $all->pluck('event_key')->flip();
        if ($all->contains(fn (TimeMovement $move): bool => ($move->metadata['kind'] ?? null) === 'SALARIO_APLICADO' && ! isset($keys['reverse:'.$move->event_key]))) {
            throw ValidationException::withMessages(['reason' => 'Hay descuento salarial aplicado: requiere reversa real de planilla, sin devolver dinero automáticamente.']);
        }
        $ids = TimeMovement::where('time_incident_id', $case->id)->whereNotNull('metadata->leave_request_id')->get()->map(fn (TimeMovement $m): int => (int) $m->metadata['leave_request_id'])->unique();
        foreach ($ids as $id) {
            $request = LeaveRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'APROBADA') {
                continue;
            }
            foreach (TimeMovement::where('metadata->leave_request_id', $id)->where('event_key', 'not like', 'reverse:%')->lockForUpdate()->get() as $move) {
                if (! $this->reverse($move, $actor, $reason)) {
                    continue;
                }
                $other = TimeIncident::whereKey($move->time_incident_id)->lockForUpdate()->firstOrFail();
                $other->pending_minutes -= $move->delta_minutes;
                $other->save();
            }
            $leave = MonthlyLeave::whereKey($request->monthly_leave_id)->lockForUpdate()->firstOrFail();
            $leave->update(['status' => $leave->month < now('America/La_Paz')->format('Y-m') ? 'VENCIDO' : 'DISPONIBLE', 'approved_by' => null, 'used_at' => null]);
            $request->update(['status' => 'REVERTIDA']);
            $this->audit->record($case->user_id, $actor->id, 'DIA_LIBRE_REVERTIDO', 'reverse-leave:'.$request->id, ['reason' => $reason, 'request_id' => $request->id]);
        }
        foreach (TimeMovement::where('time_incident_id', $case->id)->where('event_key', 'not like', 'reverse:%')->lockForUpdate()->get() as $move) {
            $this->reverse($move, $actor, $reason);
        }
    }

    private function reverse(TimeMovement $move, User $actor, string $reason): bool
    {
        $key = 'reverse:'.$move->event_key;
        if (TimeMovement::where('event_key', $key)->exists()) {
            return false;
        }
        TimeMovement::create(['user_id' => $move->user_id, 'time_incident_id' => $move->time_incident_id, 'actor_user_id' => $actor->id, 'bucket' => $move->bucket, 'delta_minutes' => -$move->delta_minutes, 'event_key' => $key, 'metadata' => ['reversal_of' => $move->id, 'reason' => $reason], 'created_at' => now()]);

        return true;
    }
}
