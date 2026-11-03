<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Calculators\DelayCalculator;
use App\Domain\ControlHorario\Calculators\EarlyDepartureMinutes;
use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Domain\ControlHorario\Queries\ShiftCoverageContext;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Domain\Usuarios\Calculators\SalaryPeriodCalculator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DetectTimeIncidentAction
{
    public function __construct(private DelayCalculator $calculator, private TimeHistoryAudit $audit) {}

    /** Entrada interna de apertura BCP confirmada; el módulo de Cajas verifica operación/titular antes de llamar. */
    public function opening(User $actor, ScheduledWorkday $workday, CarbonImmutable $instant, string $reference): ?TimeIncident
    {
        $this->authorizeSource($actor, $workday);
        if (! app(ScheduleConfiguration::class)->controlsWorkday($workday)) {
            return null;
        }

        return DB::transaction(function () use ($actor, $workday, $instant, $reference): TimeIncident {
            User::whereKey($workday->user_id)->lockForUpdate()->firstOrFail();
            $day = ScheduledWorkday::whereKey($workday->id)->lockForUpdate()->firstOrFail();
            $key = 'opening:'.$day->id;
            if ($existing = TimeIncident::where('source_key', $key)->first()) {
                return $existing;
            }
            $policy = app(PersonnelRules::class)->at($day->starts_at);
            $shift = app(ScheduleConfiguration::class)->shifts($policy)[$day->period] ?? null;
            $policy['tolerance_minutes'] = $shift['tolerance'] ?? $policy['tolerance_minutes'];
            $coverageCompletion = app(ShiftCoverageContext::class)->optionalCompletion($day);
            $effectiveStart = $coverageCompletion ? $instant : $day->starts_at;
            $calc = $this->calculator->calculate($effectiveStart, CarbonImmutable::parse($day->work_date)->isSunday() ? $effectiveStart : $instant, $policy['tolerance_minutes'], $policy['delay_multiplier'], $policy['multiplier_minutes']);
            $expires = $instant->setTimezone('America/La_Paz')->startOfDay()->addDays(5)->utc();
            $period = app(SalaryPeriodCalculator::class)->forUser(User::findOrFail($workday->user_id), $workday->starts_at);
            $incident = TimeIncident::create(['period_start' => $period['start'] ?? null, 'period_end' => $period['end'] ?? null, 'user_id' => $day->user_id, 'scheduled_workday_id' => $day->id, 'kind' => 'RETRASO',
                'status' => $calc['weighted'] > 0 ? 'CONFIRMADA' : 'SIN_RETRASO', 'source_key' => $key, 'observed_at' => $instant,
                'real_minutes' => $calc['real'], 'nominal_minutes' => $calc['weighted'], 'pending_minutes' => $calc['weighted'],
                'calculation' => array_merge($policy, $calc, ['automatic_debt' => true, 'reference' => $reference, 'starts_at' => $day->starts_at->toIso8601String(), 'coverage_completion_close_id' => $coverageCompletion?->id]),
                'expires_at' => $calc['weighted'] > 0 ? $expires : null]);
            if ($calc['weighted'] > 0) {
                TimeMovement::create(['user_id' => $day->user_id, 'time_incident_id' => $incident->id, 'actor_user_id' => $actor->id,
                    'bucket' => 'RETRASO', 'delta_minutes' => $calc['weighted'], 'event_key' => 'confirm:'.$incident->id,
                    'metadata' => ['automatic' => true, 'reference' => $reference], 'created_at' => now()]);
                app(ReconcileTimeCreditAction::class)->execute($day->user_id, 'opening-credit:'.$incident->id);
                $incident->refresh();
            }
            if ($day->attendance) {
                $day->attendance->update(['status' => 'PRESENTE']);
            }
            $this->audit->record($day->user_id, $actor->id, 'APERTURA_REFERENCIA', $key, ['incident_id' => $incident->id, 'calculation' => $calc, 'reference' => $reference]);

            return $incident;
        });
    }

    public function absence(ScheduledWorkday $workday): ?TimeIncident
    {
        if (! app(ScheduleConfiguration::class)->controlsWorkday($workday)) {
            return null;
        }
        if ($workday->ends_at <= now() && ! $workday->attendance()->exists()
            && ! app(ShiftCoverageContext::class)->optionalCompletion($workday)
            && app(ProtectCashBoxContinuityAbsenceAction::class)->shouldDefer($workday)) {
            return null;
        }

        return DB::transaction(function () use ($workday): ?TimeIncident {
            User::whereKey($workday->user_id)->lockForUpdate()->firstOrFail();
            $day = ScheduledWorkday::whereKey($workday->id)->lockForUpdate()->firstOrFail();
            if ($day->ends_at > now() || $day->attendance()->exists()) {
                return null;
            }
            if (CarbonImmutable::parse($day->work_date, 'America/La_Paz')->isSunday()) {
                $day->update(['absence_checked_at' => now()]);

                return null;
            }
            $key = 'absence:'.$day->id;
            if ($existing = TimeIncident::where('source_key', $key)->first()) {
                return $existing;
            }
            $coverageCompletion = app(ShiftCoverageContext::class)->optionalCompletion($day);
            $leave = LeaveRequest::where('scheduled_workday_id', $day->id)->where('kind', 'AUSENCIA_FUTURA')->where('status', 'APROBADA')->exists() || $coverageCompletion !== null;
            $period = app(SalaryPeriodCalculator::class)->forUser(User::findOrFail($workday->user_id), $workday->starts_at);
            $incident = TimeIncident::create(['period_start' => $period['start'] ?? null, 'period_end' => $period['end'] ?? null, 'user_id' => $day->user_id, 'scheduled_workday_id' => $day->id, 'kind' => 'FALTA',
                'status' => $leave ? 'PERMISO_AUTORIZADO' : 'FALTA_PENDIENTE', 'source_key' => $key, 'real_minutes' => (int) $day->starts_at->diffInMinutes($day->ends_at), 'nominal_minutes' => (int) $day->starts_at->diffInMinutes($day->ends_at), 'pending_minutes' => 0, 'calculation' => ['equivalent_minutes' => 360, 'coverage_completion_close_id' => $coverageCompletion?->id]]);
            $day->update(['absence_checked_at' => now()]);
            $this->audit->record($day->user_id, null, 'AUSENCIA_EVALUADA', $key, ['incident_id' => $incident->id, 'status' => $incident->status]);

            return $incident;
        });
    }

    /** Entrada interna de salida anticipada efectivamente aprobada y registrada, no una solicitud. */
    public function earlyDeparture(User $actor, ScheduledWorkday $workday, CarbonImmutable $closedAt, string $approvalReference): ?TimeIncident
    {
        $this->authorizeSource($actor, $workday);
        if (! app(ScheduleConfiguration::class)->controlsWorkday($workday)) {
            return null;
        }
        if (trim($approvalReference) === '') {
            throw ValidationException::withMessages(['reference' => 'Falta la referencia de aprobación.']);
        }
        $shift = app(ScheduleConfiguration::class)->shifts(app(PersonnelRules::class)->at($workday->starts_at))[$workday->period];
        if ($closedAt >= $workday->ends_at->subMinutes($shift['closing_minutes'])) {
            return null;
        }
        if ($closedAt < $workday->starts_at) {
            throw ValidationException::withMessages(['closed_at' => 'El cierre debe estar dentro de la jornada.']);
        }

        return DB::transaction(function () use ($actor, $workday, $closedAt, $approvalReference, $shift): TimeIncident {
            User::whereKey($workday->user_id)->lockForUpdate()->firstOrFail();
            $key = 'early:'.$workday->id;
            if ($existing = TimeIncident::where('source_key', $key)->first()) {
                return $existing;
            }
            $minutes = app(EarlyDepartureMinutes::class)->calculate($workday->ends_at, $closedAt, $shift['closing_minutes']);
            $period = app(SalaryPeriodCalculator::class)->forUser(User::findOrFail($workday->user_id), $workday->starts_at);
            $incident = TimeIncident::create(['period_start' => $period['start'] ?? null, 'period_end' => $period['end'] ?? null, 'user_id' => $workday->user_id, 'scheduled_workday_id' => $workday->id, 'kind' => 'FALTA_PARCIAL',
                'status' => 'CONFIRMADA', 'source_key' => $key, 'observed_at' => $closedAt, 'real_minutes' => $minutes, 'nominal_minutes' => $minutes,
                'pending_minutes' => $minutes, 'calculation' => ['approval_reference' => $approvalReference]]);
            TimeMovement::create(['user_id' => $workday->user_id, 'time_incident_id' => $incident->id, 'actor_user_id' => $actor->id, 'bucket' => 'FALTA_REAL', 'delta_minutes' => $minutes, 'event_key' => $key, 'metadata' => ['approval_reference' => $approvalReference], 'created_at' => now()]);
            $this->audit->record($workday->user_id, $actor->id, 'SALIDA_ANTICIPADA', $key, ['incident_id' => $incident->id, 'minutes' => $minutes]);

            return $incident;
        });
    }

    private function authorizeSource(User $actor, ScheduledWorkday $workday): void
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('panel.acceder');
        if ($user->id !== $workday->user_id) {
            app(AdministrativeReview::class)->authorize($user, 'controlhorario.revisar');
        }
    }
}
