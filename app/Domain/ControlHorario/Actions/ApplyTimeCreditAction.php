<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\ShiftCoverage;
use App\Domain\ControlHorario\Models\TimeMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyTimeCreditAction
{
    public function __construct(private ReconcileTimeCreditAction $reconcile, private TimeHistoryAudit $audit) {}

    /** Entrada interna de trabajo verificado; nunca se expone como formulario personal. */
    public function execute(ScheduledWorkday $day, CarbonImmutable $from, CarbonImmutable $to, string $reference, ?ShiftCoverage $coverage = null): void
    {
        if (trim($reference) === '' || strlen($reference) > 150 || $to <= $from || $to > Date::now('UTC')) {
            throw ValidationException::withMessages(['interval' => 'El intervalo debe estar realmente cumplido y tener referencia única.']);
        }
        DB::transaction(function () use ($day, $from, $to, $reference, $coverage): void {
            User::whereKey($day->user_id)->lockForUpdate()->firstOrFail();
            $key = 'credit:'.$reference;
            if ($existing = DB::table('time_history_events')->where('event_key', $key)->first()) {
                if ($existing->user_id !== $day->user_id) {
                    throw ValidationException::withMessages(['reference' => 'La referencia corresponde a otra persona.']);
                }

                return;
            }
            $start = $from->getTimestamp();
            $end = min($to->getTimestamp(), $day->starts_at->getTimestamp());
            $eligible = $end > $start ? [[$start, $end]] : [];
            if ($coverage && ($coverage->user_id !== $day->user_id || $coverage->credit_workday_id !== $day->id)) {
                throw ValidationException::withMessages(['interval' => 'La cobertura no corresponde a este crédito.']);
            }
            $assigned = ScheduledWorkday::when($coverage, fn ($query) => $query->whereKeyNot($coverage->workday_id))->where('user_id', $day->user_id)->where('starts_at', '<', CarbonImmutable::createFromTimestampUTC(max($start, $end)))->where('ends_at', '>', $from)->get();
            foreach ($assigned as $assignedDay) {
                $eligible = $this->subtract($eligible, $assignedDay->starts_at->getTimestamp(), $assignedDay->ends_at->getTimestamp());
            }
            $old = DB::table('time_history_events')->where('user_id', $day->user_id)->where('action', 'CREDITO_TRABAJO')
                ->where('details->from', '<', max($start, $end))->where('details->to', '>', $start)->pluck('details');
            $ranges = [];
            foreach ($old as $details) {
                $data = json_decode($details, true, 512, JSON_THROW_ON_ERROR);
                $ranges = array_merge($ranges, $data['eligible_ranges'] ?? [[$data['from'], $data['to']]]);
            }
            $before = $this->unionSeconds($ranges);
            $after = $this->unionSeconds(array_merge($ranges, $eligible));
            $minutes = intdiv($after, 60) - intdiv($before, 60);
            if ($minutes > 0) {
                TimeMovement::create(['user_id' => $day->user_id, 'time_incident_id' => null, 'actor_user_id' => null, 'bucket' => 'CREDITO', 'delta_minutes' => $minutes, 'event_key' => $key, 'metadata' => ['workday_id' => $day->id, 'reference' => $reference], 'created_at' => now()]);
            }
            $this->audit->record($day->user_id, null, 'CREDITO_TRABAJO', $key, ['workday_id' => $day->id, 'from' => $start, 'to' => max($start, $end), 'eligible_ranges' => $eligible, 'minutes' => $minutes, 'reference' => $reference]);
            $this->reconcile->execute($day->user_id, $key);
        });
    }

    /** @param array<int,array{0:int,1:int}> $ranges @return array<int,array{0:int,1:int}> */
    private function subtract(array $ranges, int $left, int $right): array
    {
        $result = [];
        foreach ($ranges as [$start,$end]) {
            if ($end <= $left || $start >= $right) {
                $result[] = [$start, $end];

                continue;
            }
            if ($start < $left) {
                $result[] = [$start, min($left, $end)];
            }
            if ($end > $right) {
                $result[] = [max($start, $right), $end];
            }
        }

        return $result;
    }

    /** @param array<int,array{0:int,1:int}> $ranges */
    private function unionSeconds(array $ranges): int
    {
        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $total = 0;
        $start = null;
        $end = 0;
        foreach ($ranges as [$left,$right]) {
            if ($right <= $left) {
                continue;
            }
            if ($start === null) {
                $start = $left;
                $end = $right;
            } elseif ($left <= $end) {
                $end = max($end, $right);
            } else {
                $total += $end - $start;
                $start = $left;
                $end = $right;
            }
        }

        return $total + ($start === null ? 0 : $end - $start);
    }
}
