<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\CashBoxWorkdayContinuity;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Queries\CashBoxContinuityCandidatesQuery;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Domain\ControlHorario\Queries\ShiftCoverageContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class CaptureCashBoxContinuityAction
{
    /** A valid PIN for another box also proves the owner is continuing their existing session. */
    public function afterVerifiedPin(User $actor): void
    {
        if (! app(ScheduleConfiguration::class)->enabled()) {
            return;
        }
        try {
            foreach (CashBoxSession::where('user_id', $actor->id)->where('status', 'ABIERTA')->whereNotNull('active_slot')->get() as $session) {
                $this->onResume($actor, $session);
            }
        } catch (Throwable $error) {
            report($error);
        }
    }

    /** Authenticated return to an owned, open session. Never asks for a new PIN. */
    public function onResume(User $actor, CashBoxSession $session): void
    {
        if (! app(ScheduleConfiguration::class)->enabled()) {
            return;
        }
        try {
            $fresh = CashBoxSession::findOrFail($session->id);
            $this->authorize($actor, $fresh);
            if ($fresh->status === 'ABIERTA' && $fresh->active_slot !== null) {
                $this->capture($actor, $fresh, now('UTC')->toImmutable(), 'RESUME');
            }
        } catch (Throwable $error) {
            report($error);
        }
    }

    /** Only a persisted operation is accepted, never an amount/date supplied by the browser. */
    public function fromOperation(CashBoxOperation $operation): void
    {
        if (! app(ScheduleConfiguration::class)->enabled()) {
            return;
        }
        try {
            $operation = CashBoxOperation::findOrFail($operation->id);
            $session = CashBoxSession::findOrFail($operation->cash_box_session_id);
            $actor = User::findOrFail($operation->user_id);
            $this->authorize($actor, $session);
            $this->capture($actor, $session, $operation->created_at->toImmutable(), 'OPERATION', $operation->id);
        } catch (Throwable $error) {
            report($error);
        }
    }

    private function authorize(User $actor, CashBoxSession $session): void
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('cajas.operar');
        if (! $user->is_active || $session->user_id !== $user->id) {
            throw new AuthorizationException;
        }
    }

    private function capture(User $actor, CashBoxSession $session, CarbonImmutable $instant, string $kind, ?int $operationId = null): void
    {
        if (! app(ScheduleConfiguration::class)->allows($session->cash_box_id)) {
            return;
        }
        if ($instant > now('UTC') || $instant < $session->opened_at || ($session->closed_at && $instant > $session->closed_at)) {
            return;
        }
        app(EnsureRecurringWorkdaysAction::class)->execute($actor, $instant);
        $originalDay = $session->scheduled_workday_id ? ScheduledWorkday::find($session->scheduled_workday_id) : null;
        $coverage = $originalDay ? app(ShiftCoverageContext::class)->forWorkday($originalDay) : null;
        if ($coverage?->credit_workday_id && $instant <= $coverage->ends_at) {
            return; // Completing the morning boundary is not proof of working the optional afternoon.
        }
        $day = ScheduledWorkday::where('user_id', $actor->id)->where('work_date', $instant->setTimezone('America/La_Paz')->toDateString())
            ->where('starts_at', '<=', $instant)->where('ends_at', '>=', $instant)->orderByDesc('starts_at')->first();
        if (! $day || $session->opened_at > $day->starts_at || ! app(ScheduleConfiguration::class)->controlsWorkday($day)) {
            return;
        }
        $opening = CashBoxScheduleEvent::where('source_key', 'opening:'.$session->id)->first();
        if (! $opening) {
            return;
        }
        app(ProcessCashBoxScheduleEventAction::class)->safely($opening->id);
        $opening->refresh();
        if (! app(CashBoxContinuityCandidatesQuery::class)->isAdjacent($opening, $day)) {
            return;
        }
        $link = DB::transaction(function () use ($actor, $session, $opening, $day, $instant, $kind, $operationId) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();

            return CashBoxWorkdayContinuity::firstOrCreate(['cash_box_session_id' => $session->id, 'scheduled_workday_id' => $day->id],
                ['user_id' => $actor->id, 'opening_event_id' => $opening->id, 'observed_at' => $instant, 'effective_at' => $day->starts_at,
                    'evidence_kind' => $kind, 'operation_id' => $operationId]);
        });
        app(ProcessCashBoxContinuityAction::class)->safely($link->id);
    }
}
