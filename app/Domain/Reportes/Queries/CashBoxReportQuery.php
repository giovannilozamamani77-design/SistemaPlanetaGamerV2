<?php

namespace App\Domain\Reportes\Queries;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class CashBoxReportQuery
{
    public function filters(User $actor, array $input): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        foreach (['user_id', 'box_id'] as $key) {
            $input[$key] = filled($input[$key] ?? null) ? $input[$key] : null;
        }

        return Validator::make($input, [
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('America/La_Paz')->toDateString()],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.now('America/La_Paz')->toDateString()],
            'user_id' => ['nullable', 'integer', 'exists:users,id'], 'box_id' => ['nullable', 'integer', 'exists:cash_boxes,id'],
        ])->validate();
    }

    private function scope(Builder $query, array $filters, string $user, string $box): Builder
    {
        return $query->when($filters['user_id'], fn (Builder $q) => $q->where($user, $filters['user_id']))
            ->when($filters['box_id'], fn (Builder $q) => $q->where($box, $filters['box_id']));
    }

    private function range(Builder $query, string $column, string $from, string $until): Builder
    {
        return $query->where($column, '>=', $from)->where($column, '<', $until);
    }

    public function execute(User $actor, array $input, array $pages = []): array
    {
        $filters = $this->filters($actor, $input);
        Validator::make($pages, ['operations' => ['sometimes', 'integer', 'between:1,1000000'], 'counters' => ['sometimes', 'integer', 'between:1,1000000'], 'shortages' => ['sometimes', 'integer', 'between:1,1000000']])->validate();
        $start = CarbonImmutable::parse($filters['from'], 'America/La_Paz');
        $end = CarbonImmutable::parse($filters['until'], 'America/La_Paz');
        $from = $start->utc()->format('Y-m-d H:i:s');
        $until = $end->addDay()->utc()->format('Y-m-d H:i:s');

        return DB::transaction(function () use ($filters, $from, $until, $end, $pages): array {
            $operations = $this->scope(DB::table('cash_box_operations as o')->join('cash_box_sessions as s', 's.id', '=', 'o.cash_box_session_id'), $filters, 'o.user_id', 's.cash_box_id');
            $this->range($operations, 'o.created_at', $from, $until);
            $cancelled = (clone $operations)->where('o.status', '<>', 'VIGENTE')->count();
            $operations->where('o.status', 'VIGENTE');
            $opGroups = (clone $operations)->select('o.user_id')->selectRaw('COUNT(*) as operations, SUM(o.fee_cents) as fees')->groupBy('o.user_id')->get()->keyBy('user_id');
            $counterBase = $this->scope(DB::table('cash_box_counter_readings as c')->join('cash_box_closes as cl', 'cl.id', '=', 'c.cash_box_close_id')
                ->whereNull('c.voided_at')->whereNull('cl.cancelled_at'), $filters, 'c.user_id', 'c.cash_box_id');
            $this->range($counterBase, 'c.created_at', $from, $until);
            $counterGroups = (clone $counterBase)->select('c.user_id')->selectRaw('SUM(CASE WHEN c.baseline = 0 THEN c.delta ELSE 0 END) as general, SUM(COALESCE(c.fixed_delta,0)) as fixed, SUM(CASE WHEN c.baseline = 1 THEN 1 ELSE 0 END) as baselines, SUM(CASE WHEN c.fixed_delta IS NULL THEN 1 ELSE 0 END) as unknown_fixed')->groupBy('c.user_id')->get()->keyBy('user_id');
            $ids = $opGroups->keys()->merge($counterGroups->keys())->unique();
            $names = User::whereIn('id', $ids)->pluck('name', 'id');
            $ranking = $ids->map(fn ($id): array => [
                'user_id' => (int) $id, 'name' => $names->get($id, 'Usuario histórico'),
                'operations' => (int) ($opGroups->get($id)?->operations ?? 0), 'fees' => (int) ($opGroups->get($id)?->fees ?? 0),
                'general' => (int) ($counterGroups->get($id)?->general ?? 0), 'fixed' => (int) ($counterGroups->get($id)?->fixed ?? 0),
                'baselines' => (int) ($counterGroups->get($id)?->baselines ?? 0), 'unknown_fixed' => (int) ($counterGroups->get($id)?->unknown_fixed ?? 0),
            ])->sortByDesc('general')->values()->all();
            $zone = DB::connection()->getDriverName() === 'sqlite' ? "date(o.created_at, '-4 hours')" : 'DATE(DATE_SUB(o.created_at, INTERVAL 4 HOUR))';
            $daily = (clone $operations)->selectRaw($zone.' as day, COUNT(*) as operations, SUM(o.fee_cents) as fees')->groupByRaw($zone)->orderByRaw($zone)->get();
            $monthStart = $end->startOfMonth()->utc()->format('Y-m-d H:i:s');
            $shortages = $this->shortages($filters);
            $this->range($shortages, 'i.occurred_at', $monthStart, $until);
            $confirmed = (clone $shortages)->whereNotIn('i.status', ['DETECTADA', 'EN_REVISION', 'ANULADA', 'CARGO_BANCARIO'])->selectRaw('COALESCE(SUM(i.amount_cents),0) as total, COALESCE(SUM(i.pending_cents),0) as pending')->first();
            $unlinked = $this->shortages(array_replace($filters, ['box_id' => null]));
            $this->range($unlinked, 'i.occurred_at', $monthStart, $until);
            $unlinkedCount = $unlinked->whereNull('box_link.box_id')->count();
            $underReview = (int) (clone $shortages)->whereIn('i.status', ['DETECTADA', 'EN_REVISION'])->sum('i.amount_cents');
            $openings = $this->openings($filters, $from, $until);
            $chart = fn (string $label, string $field, string $color): array => ['label' => $label, 'data' => array_column($ranking, $field), 'backgroundColor' => $color, 'borderColor' => $color];

            return [
                'filters' => $filters, 'month_label' => $end->format('m/Y'), 'checked_at' => now('America/La_Paz')->format('d/m/Y H:i:s'),
                'operations' => array_sum(array_column($ranking, 'operations')), 'fees' => array_sum(array_column($ranking, 'fees')),
                'general' => array_sum(array_column($ranking, 'general')), 'fixed' => array_sum(array_column($ranking, 'fixed')),
                'baselines' => array_sum(array_column($ranking, 'baselines')), 'unknown_fixed' => array_sum(array_column($ranking, 'unknown_fixed')),
                'cancelled_operations' => $cancelled, 'ranking' => $ranking,
                'shortages' => ['total' => (int) $confirmed->total, 'pending' => (int) $confirmed->pending, 'review' => $underReview], 'unlinked_shortages' => $unlinkedCount,
                'opening_averages' => $openings,
                'charts' => [
                    'bcp' => ['labels' => array_column($ranking, 'name'), 'datasets' => [$chart('Generales BCP', 'general', '#22d3ee'), $chart('Fijas BCP', 'fixed', '#a78bfa')]],
                    'operations' => ['labels' => array_column($ranking, 'name'), 'datasets' => [$chart('Operaciones registradas', 'operations', '#34d399')]],
                    'fees' => ['labels' => $daily->pluck('day')->all(), 'datasets' => [['label' => 'Comisiones · Bs', 'data' => $daily->map(fn ($row) => (int) $row->fees / 100)->all(), 'borderColor' => '#a78bfa', 'backgroundColor' => '#a78bfa']]],
                ],
                'operation_records' => (clone $operations)->join('users as u', 'u.id', '=', 'o.user_id')->join('cash_boxes as b', 'b.id', '=', 's.cash_box_id')
                    ->select('o.id', 'o.created_at', 'o.fee_type', 'o.bill_count', 'o.amount_cents', 'o.fee_cents', 'u.name as user_name', 'b.name as box_name')->orderByDesc('o.created_at')->orderByDesc('o.id')->paginate(10, ['*'], 'cashOperationsPage', $pages['operations'] ?? 1),
                'counter_records' => (clone $counterBase)->join('users as u', 'u.id', '=', 'c.user_id')->join('cash_boxes as b', 'b.id', '=', 'c.cash_box_id')
                    ->select('c.id', 'c.created_at', 'c.reading', 'c.delta', 'c.fixed_reading', 'c.fixed_delta', 'c.baseline', 'u.name as user_name', 'b.name as box_name')->orderByDesc('c.id')->paginate(10, ['*'], 'cashCountersPage', $pages['counters'] ?? 1),
                'shortage_records' => (clone $shortages)->leftJoin('users as u', 'u.id', '=', 'i.user_id')->select('i.id', 'i.occurred_at', 'i.box_label', 'i.status', 'i.amount_cents', 'i.pending_cents', 'u.name as user_name')->orderByDesc('i.occurred_at')->orderByDesc('i.id')->paginate(10, ['*'], 'cashShortagesPage', $pages['shortages'] ?? 1),
            ];
        });
    }

    private function shortages(array $filters): Builder
    {
        $closes = DB::table('cash_box_closes')->whereNotNull('economic_incident_id')->selectRaw('economic_incident_id as incident_id, cash_box_id as box_id');
        $receipts = DB::table('cash_box_receipt_discrepancies as r')->join('cash_box_sessions as s', 's.id', '=', 'r.cash_box_session_id')->whereNotNull('r.economic_incident_id')->selectRaw('r.economic_incident_id as incident_id, s.cash_box_id as box_id');
        $links = DB::query()->fromSub($closes->unionAll($receipts), 'origins')->selectRaw('incident_id, MIN(box_id) as box_id')->groupBy('incident_id');

        return $this->scope(DB::table('economic_incidents as i')->leftJoinSub($links, 'box_link', 'box_link.incident_id', '=', 'i.id')->where('i.source_module', 'cajas'), $filters, 'i.user_id', 'box_link.box_id');
    }

    private function openings(array $filters, string $from, string $until): array
    {
        $query = $this->scope(DB::table('cash_box_sessions as s')->join('cash_boxes as b', 'b.id', '=', 's.cash_box_id')
            ->leftJoin('cash_box_schedule_events as e', function ($join): void {
                $join->on('e.cash_box_session_id', '=', 's.id')->where('e.kind', 'OPENING');
            })
            ->leftJoin('scheduled_workdays as d', 'd.id', '=', 'e.scheduled_workday_id'), $filters, 's.user_id', 's.cash_box_id');
        $this->range($query, 's.opened_at', $from, $until);
        $groups = [];
        foreach ($query->select('s.id', 's.opened_at', 'b.id as box_id', 'b.name', 'd.period', 'd.starts_at')->orderBy('s.id')->lazyById(500, 's.id', 'id') as $row) {
            $period = in_array($row->period, ['manana', 'tarde'], true) ? $row->period : 'unknown';
            $key = $row->box_id.':'.$period;
            $time = CarbonImmutable::parse($row->opened_at, 'UTC')->timezone('America/La_Paz');
            $seconds = $time->hour * 3600 + $time->minute * 60 + $time->second;
            $groups[$key] ??= ['box' => $row->name, 'period' => $period, 'count' => 0, 'seconds' => 0, 'scheduled_seconds' => 0, 'offset_seconds' => 0];
            $groups[$key]['count']++;
            $groups[$key]['seconds'] += $seconds;
            if ($period !== 'unknown' && $row->starts_at) {
                $scheduled = CarbonImmutable::parse($row->starts_at, 'UTC')->timezone('America/La_Paz');
                $groups[$key]['scheduled_seconds'] += $scheduled->hour * 3600 + $scheduled->minute * 60;
                $groups[$key]['offset_seconds'] += $time->getTimestamp() - $scheduled->getTimestamp();
            }
        }

        return array_values(array_map(function (array $group): array {
            $seconds = (int) round($group['seconds'] / $group['count']);
            $clock = fn (int $value): string => sprintf('%02d:%02d', intdiv($value, 3600), intdiv($value % 3600, 60));

            return ['box' => $group['box'], 'period' => $group['period'], 'count' => $group['count'], 'average' => $clock($seconds), 'position' => round($seconds / 86400 * 100, 2),
                'scheduled' => $group['period'] === 'unknown' ? null : $clock((int) round($group['scheduled_seconds'] / $group['count'])),
                'offset_minutes' => $group['period'] === 'unknown' ? null : round($group['offset_seconds'] / $group['count'] / 60, 1)];
        }, $groups));
    }
}
