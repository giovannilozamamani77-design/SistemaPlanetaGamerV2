<?php

namespace App\Domain\Cajas\Queries;

use App\Domain\Cajas\Models\CashBoxEarlyRequest;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Calculators\EarlyDepartureMinutes;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\CashBoxWorkdayContinuity;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** The same read-only time gate is used before navigation and at final confirmation. */
class CashBoxClosingAccessQuery
{
    public function execute(User $actor, int $sessionId, ?string $period = null): array
    {
        $context = app(CashBoxClosingStartQuery::class)->execute($actor, $sessionId);
        $session = CashBoxSession::findOrFail($sessionId);
        $empty = ['allowed' => true, 'early' => false, 'minutes' => 0, 'departure_minutes' => 0, 'period' => $period,
            'workday_id' => null, 'closing_from' => null, 'request' => null, 'message' => null];
        if (! app(ScheduleConfiguration::class)->allows($session->cash_box_id)) {
            return $empty;
        }
        if (CashBoxScheduleEvent::where('cash_box_session_id', $sessionId)->whereIn('status', ['PENDING', 'FAILED'])->exists()
            || CashBoxWorkdayContinuity::where('cash_box_session_id', $sessionId)->where('status', '!=', 'DONE')->exists()) {
            return array_replace($empty, ['allowed' => false, 'message' => 'La evaluación de tu jornada está pendiente. Puedes seguir trabajando; vuelve a intentar el cierre cuando se complete.']);
        }
        $continuity = CashBoxWorkdayContinuity::where('cash_box_session_id', $sessionId)->where('user_id', $actor->id)
            ->where('status', 'DONE')->where('effective_at', '<=', now())->latest('effective_at')->first();
        $dayId = $continuity?->scheduled_workday_id ?? $session->scheduled_workday_id;
        $day = $dayId ? ScheduledWorkday::whereKey($dayId)->where('user_id', $actor->id)->first() : null;
        if ($day) {
            $period ??= $day->period;
            if (! app(ScheduleConfiguration::class)->controlsWorkday($day)) {
                return $empty;
            }
            if ($period !== $day->period) {
                throw ValidationException::withMessages(['period' => 'Selecciona el turno de tu jornada actual.']);
            }
            $end = $day->ends_at;
            $policy = app(PersonnelRules::class)->at($day->starts_at);
        } else {
            // Legacy financial sessions retain their configured time window without inventing attendance.
            $local = now('America/La_Paz');
            $period ??= isset($context['shifts']['tarde']) && $local->format('H:i') >= $context['shifts']['tarde']['start'] ? 'tarde' : 'manana';
            $shift = $context['shifts'][$period] ?? null;
            if (! $shift) {
                return $empty;
            }
            $date = $session->opened_at->setTimezone('America/La_Paz')->startOfDay();
            $end = $date->setTimeFromTimeString($shift['end']);
            if ($end <= $date->setTimeFromTimeString($shift['start'])) {
                $end = $end->addDay();
            }
            $policy = app(PersonnelRules::class)->at($date->setTimeFromTimeString($shift['start']));
        }
        if (! app(ScheduleConfiguration::class)->periodEnabled($period, $policy)) {
            return $empty;
        }
        $limit = CarbonImmutable::instance($end)->subMinutes(app(ScheduleConfiguration::class)->shifts($policy)[$period]['closing_minutes']);
        $early = now('UTC')->lt($limit);
        $request = CashBoxEarlyRequest::where('cash_box_session_id', $sessionId)->where('period', $period)->first();
        $minutes = $early ? (int) ceil(now('UTC')->diffInSeconds($limit) / 60) : 0;

        return ['allowed' => ! $early || $request?->status === 'APROBADA', 'early' => $early, 'minutes' => $minutes,
            'departure_minutes' => $day ? app(EarlyDepartureMinutes::class)->calculate(CarbonImmutable::instance($end), now('UTC')->toImmutable(), app(ScheduleConfiguration::class)->shifts($policy)[$period]['closing_minutes']) : 0,
            'period' => $period, 'workday_id' => $day?->id, 'closing_from' => $limit->setTimezone('America/La_Paz')->format('H:i'),
            'request' => $request, 'message' => $early ? 'Aún faltan '.$minutes.' minutos para habilitar el cierre. Puedes solicitar un cierre anticipado o seguir trabajando.' : null];
    }

    public function assertAllowed(User $actor, int $sessionId, ?string $period = null): array
    {
        $result = $this->execute($actor, $sessionId, $period);
        if (! $result['allowed']) {
            throw ValidationException::withMessages(['closing' => $result['message']]);
        }

        return $result;
    }
}
