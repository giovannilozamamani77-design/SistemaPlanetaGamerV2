<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Queries\CashBoxClosingAccessQuery;
use App\Domain\Cajas\Queries\CashBoxClosingStartQuery;
use App\Domain\ControlHorario\Actions\CaptureCashBoxContinuityAction;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Queries\ShiftCoverageContext;
use App\Models\User;

class PrepareCashBoxClosingAccessAction
{
    public function execute(User $actor, int $sessionId, ?string $period = null): array
    {
        app(CashBoxClosingStartQuery::class)->execute($actor, $sessionId);
        $session = CashBoxSession::findOrFail($sessionId);
        $day = $session->scheduled_workday_id ? ScheduledWorkday::find($session->scheduled_workday_id) : null;
        $coverage = $day ? app(ShiftCoverageContext::class)->forWorkday($day) : null;
        // Finalizing the chosen covered period is not evidence of starting another one.
        // Existing continuity is still evaluated by the gate and cannot be overwritten.
        if (! $coverage?->credit_workday_id || $period !== $day->period) {
            app(CaptureCashBoxContinuityAction::class)->onResume($actor, $session);
        }

        return app(CashBoxClosingAccessQuery::class)->execute($actor, $sessionId, $period);
    }
}
