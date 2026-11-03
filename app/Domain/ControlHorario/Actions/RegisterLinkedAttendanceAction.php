<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\Attendance;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RegisterLinkedAttendanceAction
{
    /** Contrato interno: Cajas verifica continuidad/titular y proporciona un PIN realmente validado, nunca un timestamp del cliente. */
    public function execute(User $actor, ScheduledWorkday $day, int $verifiedPinAuditId, string $reference, CarbonImmutable $operationAt): Attendance
    {
        if ($actor->id !== $day->user_id) {
            throw new AuthorizationException;
        }
        if (trim($reference) === '' || $operationAt > Date::now('UTC') || $operationAt->setTimezone('America/La_Paz')->format('Y-m-d') !== $day->work_date) {
            throw ValidationException::withMessages(['reference' => 'La actividad registrada debe corresponder realmente a esta jornada.']);
        }

        return DB::transaction(function () use ($actor, $day, $verifiedPinAuditId, $reference, $operationAt): Attendance {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('controlhorario.asistencia.registrar');
            $proof = DB::table('user_audits')->where('id', $verifiedPinAuditId)->where('actor_user_id', $user->id)->where('subject_user_id', $user->id)->where('action', 'pin_verified')->first();
            if (! $proof) {
                throw ValidationException::withMessages(['reference' => 'No existe una validación de PIN de esta cajera.']);
            }
            $pinAt = CarbonImmutable::parse($proof->created_at, 'UTC');
            if ($pinAt > $operationAt || $pinAt->setTimezone('America/La_Paz')->format('Y-m-d') !== $day->work_date) {
                throw ValidationException::withMessages(['reference' => 'El PIN no corresponde a esta fecha/actividad.']);
            }
            $attendance = Attendance::firstOrCreate(['scheduled_workday_id' => $day->id], ['first_pin_at' => $pinAt, 'status' => 'PRESENTE_SIN_APERTURA']);
            if ($attendance->wasRecentlyCreated) {
                app(TimeHistoryAudit::class)->record($user->id, $user->id, 'ASISTENCIA_ENLAZADA', 'linked-attendance:'.$day->id, ['workday_id' => $day->id, 'pin_audit_id' => $proof->id, 'reference' => $reference, 'operation_at' => $operationAt->toIso8601String()]);
            } elseif ($pinAt < $attendance->first_pin_at) {
                // A durable source may arrive out of order after an interruption.
                $attendance->update(['first_pin_at' => $pinAt]);
                app(TimeHistoryAudit::class)->record($user->id, $user->id, 'PIN_ANTERIOR_RECUPERADO',
                    'earlier-linked-pin:'.$day->id.':'.$proof->id,
                    ['workday_id' => $day->id, 'pin_audit_id' => $proof->id, 'reference' => $reference]);
            }

            return $attendance;
        });
    }
}
