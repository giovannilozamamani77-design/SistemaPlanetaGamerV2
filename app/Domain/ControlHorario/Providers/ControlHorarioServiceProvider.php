<?php

namespace App\Domain\ControlHorario\Providers;

use App\Domain\ControlHorario\Commands\ProcessPersonalHistoryCommand;
use App\Domain\ControlHorario\Commands\RetryCashBoxScheduleEventsCommand;
use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Policies\LeaveRequestPolicy;
use App\Domain\ControlHorario\Policies\ScheduledWorkdayPolicy;
use App\Domain\ControlHorario\Policies\TimeIncidentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ControlHorarioServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/control_horario'));
        Gate::policy(ScheduledWorkday::class, ScheduledWorkdayPolicy::class);
        Gate::policy(TimeIncident::class, TimeIncidentPolicy::class);
        Gate::policy(LeaveRequest::class, LeaveRequestPolicy::class);
        $this->commands([ProcessPersonalHistoryCommand::class, RetryCashBoxScheduleEventsCommand::class]);
    }
}
