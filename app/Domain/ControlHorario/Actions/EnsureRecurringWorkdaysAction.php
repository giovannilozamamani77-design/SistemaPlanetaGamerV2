<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Models\RecurringWorkShift;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EnsureRecurringWorkdaysAction
{
    /** Internal only: the instant comes from a verified PIN, persisted operation, or server clock. */
    public function execute(User $actor, CarbonImmutable $instant, bool $forAbsences = false): void
    {
        if (! app(ScheduleConfiguration::class)->enabled() || $instant > now('UTC')) {
            return;
        }
        $local = $instant->setTimezone('America/La_Paz');
        if ($local->isSunday()) {
            return;
        }
        DB::transaction(function () use ($actor, $instant, $local, $forAbsences): void {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! $user->is_active || ! $user->hasRole('cajera')) {
                return;
            }
            $assignments = RecurringWorkShift::where('user_id', $user->id)->effectiveAt($instant)->get();
            $rules = app(PersonnelRules::class)->at($instant);
            $shifts = app(ScheduleConfiguration::class)->shifts($rules);
            $candidate = $assignments->filter(fn ($a) => $a->enabled)
                ->sortBy(fn ($a) => $shifts[$a->period]['start'])
                ->first(fn ($a) => $local->startOfDay()->setTimeFromTimeString($shifts[$a->period]['end'])->utc() > $instant);
            foreach ($assignments as $assignment) {
                if (! $assignment->enabled || (! $forAbsences && $candidate?->id !== $assignment->id)) {
                    continue;
                }
                $shift = app(ScheduleConfiguration::class)->shifts($rules)[$assignment->period];
                $start = $local->startOfDay()->setTimeFromTimeString($shift['start'])->utc();
                $end = $local->startOfDay()->setTimeFromTimeString($shift['end'])->utc();
                $startRules = app(PersonnelRules::class)->at($start);
                $startShift = app(ScheduleConfiguration::class)->shifts($startRules)[$assignment->period];
                // No absence is invented by assigning or changing a shift after it started.
                if ($forAbsences && ($startShift['start'] !== $shift['start'] || $startShift['end'] !== $shift['end'])) {
                    continue;
                }
                if ($forAbsences && ($end > $instant || $assignment->created_at > $start || ! (bool) ($startRules['cash_box_controls_enabled'] ?? config('cajas.schedule_controls_enabled')) || ! ($startRules['schedule_enabled'] ?? true) || ! app(ScheduleConfiguration::class)->periodEnabled($assignment->period, $startRules))) {
                    continue;
                }
                if (ScheduledWorkday::where('user_id', $user->id)->where('work_date', $local->toDateString())->where('period', $assignment->period)->exists()) {
                    continue;
                }
                // A dated exception takes precedence; no overlapping second shift is invented.
                if (ScheduledWorkday::where('user_id', $user->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                    continue;
                }
                $day = ScheduledWorkday::create(['user_id' => $user->id, 'created_by' => $assignment->created_by, 'work_date' => $local->toDateString(),
                    'period' => $assignment->period, 'starts_at' => $start, 'ends_at' => $end]);
                DB::table('workday_audits')->insert(['actor_user_id' => $assignment->created_by, 'scheduled_workday_id' => $day->id, 'action' => 'recurring_scheduled',
                    'changes' => json_encode(['recurring_work_shift_id' => $assignment->id, 'revision' => $assignment->revision, 'weekdays' => 'lunes_sabado'], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }
        });
    }

    public function todayForAbsences(): void
    {
        if (! app(ScheduleConfiguration::class)->enabled() || now('America/La_Paz')->isSunday()) {
            return;
        }
        RecurringWorkShift::current()->where('enabled', true)->select('user_id')->distinct()->orderBy('user_id')->chunk(100, function ($rows): void {
            foreach ($rows as $row) {
                $this->execute(User::findOrFail($row->user_id), now('UTC')->toImmutable(), true);
            }
        });
    }
}
