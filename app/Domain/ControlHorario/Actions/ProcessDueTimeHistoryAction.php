<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\ScheduledWorkday;

class ProcessDueTimeHistoryAction
{
    public function execute(): void
    {
        app(EnsureRecurringWorkdaysAction::class)->todayForAbsences();
        ScheduledWorkday::whereNull('absence_checked_at')->where('ends_at', '<=', now())->whereDoesntHave('attendance')->whereDoesntHave('timeIncidents', fn ($q) => $q->where('kind', 'FALTA'))
            ->chunkById(100, function ($days): void {
                foreach ($days as $day) {
                    app(DetectTimeIncidentAction::class)->absence($day);
                }
            });
    }
}
