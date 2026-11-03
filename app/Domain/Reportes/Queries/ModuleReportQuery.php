<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Reportes\Calculators\DailyComparisonCalculator;
use App\Domain\Reportes\Data\ModuleReportDefinition;
use App\Domain\Reportes\Data\ReportPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class ModuleReportQuery
{
    public function execute(User $actor, string $module, string $month): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        $definition = app(ModuleReportDefinition::class)->get($module);
        $period = $this->period($actor, $month);

        return DB::transaction(function () use ($actor, $module, $month, $definition, $period): array {
            $query = app(DailyReportRecordsQuery::class);
            $current = $query->selectedContributions($actor, $period['start'], $period['end'], $definition['sources']);
            $labels = $query->sourceLabels();
            $grouped = (clone $current)->selectRaw('source, COUNT(*) as operations, COALESCE(SUM(sales_cents),0) as sales_cents, COALESCE(SUM(collections_cents),0) as collections_cents')->groupBy('source')->get()->keyBy('source');
            $rows = [];
            foreach ($definition['sources'] as $source) {
                $row = $grouped->get($source);
                $rows[$source] = ['source' => $source, 'label' => $labels[$source], 'operations' => (int) ($row?->operations ?? 0), 'sales_cents' => (int) ($row?->sales_cents ?? 0), 'collections_cents' => (int) ($row?->collections_cents ?? 0)];
            }
            $sales = array_sum(array_column($rows, 'sales_cents'));
            $collections = array_sum(array_column($rows, 'collections_cents'));
            $previous = $period['comparison_available'] ? $query->selectedContributions($actor, $period['previous_start'], $period['previous_end'], $definition['sources'])->selectRaw('COALESCE(SUM(sales_cents),0) as sales, COALESCE(SUM(collections_cents),0) as collections')->first() : null;
            $margin = app(ModuleMarginQuery::class)->execute($actor, $module, $period['start'], $period['end'], $rows);
            $expression = DB::connection()->getDriverName() === 'sqlite' ? "date(recorded_at, '-4 hours')" : 'DATE(DATE_SUB(recorded_at, INTERVAL 4 HOUR))';
            $daily = (clone $current)->selectRaw($expression.' as day, SUM(collections_cents) as collections')->groupByRaw($expression)->get()->keyBy('day');
            $trend = [];
            for ($day = CarbonImmutable::parse($period['start']); $day->toDateString() <= $period['end']; $day = $day->addDay()) {
                $key = $day->toDateString();
                $trend[] = ['day' => $key, 'label' => $day->format('d/m'), 'collections_cents' => (int) ($daily->get($key)?->collections ?? 0), 'margin_cents' => $module === 'BOXES' ? (int) ($daily->get($key)?->collections ?? 0) : ($margin['daily'][$key] ?? 0)];
            }

            $distribution = array_map(fn (array $row): array => ['label' => $row['label'], 'cents' => $row['collections_cents']], array_values($rows));
            if ($module === 'BOXES') {
                $context = $query->context($actor, $period['start'], untilDate: $period['end']);
                $distribution = DB::table('cash_box_operations as operation')->join('cash_box_sessions as session', 'session.id', '=', 'operation.cash_box_session_id')->join('cash_boxes as box', 'box.id', '=', 'session.cash_box_id')
                    ->where('operation.status', 'VIGENTE')->where('operation.created_at', '>=', $context['from'])->where('operation.created_at', '<', $context['until'])
                    ->select('box.id', 'box.name')->selectRaw('SUM(operation.fee_cents) as amount')->groupBy('box.id', 'box.name')->orderBy('box.id')->get()
                    ->map(fn ($row): array => ['label' => $row->name, 'cents' => (int) $row->amount])->all();
            }
            $charts = [
                'trend' => ['labels' => array_column($trend, 'label'), 'datasets' => [
                    ['label' => 'Cobros · Bs', 'data' => array_map(fn ($row) => $row['collections_cents'] / 100, $trend), 'borderColor' => '#22d3ee', 'backgroundColor' => '#22d3ee', 'tension' => 0.2],
                    ['label' => 'Resultado conocido · Bs'.($margin['pending'] > 0 ? ' (parcial)' : ''), 'data' => array_map(fn ($row) => $row['margin_cents'] / 100, $trend), 'borderColor' => '#a78bfa', 'backgroundColor' => '#a78bfa', 'tension' => 0.2],
                ]],
                'distribution' => ['labels' => array_column($distribution, 'label'), 'datasets' => [['label' => 'Cobros · Bs', 'data' => array_map(fn ($row) => $row['cents'] / 100, $distribution), 'backgroundColor' => ['#22d3ee', '#a78bfa', '#facc15', '#34d399', '#fb7185']]]],
            ];

            return ['module' => $module, 'month' => $month, 'definition' => $definition, 'period' => $period, 'rows' => $rows, 'sales_cents' => $sales, 'collections_cents' => $collections, 'operations' => array_sum(array_column($rows, 'operations')), 'margin' => $margin, 'trend' => $trend, 'charts' => $charts,
                'comparison' => $previous === null ? null : ['sales' => app(DailyComparisonCalculator::class)->compare($sales, (int) $previous->sales), 'collections' => app(DailyComparisonCalculator::class)->compare($collections, (int) $previous->collections)]];
        });
    }

    public function period(User $actor, string $month): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        Validator::make(['month' => $month], ['month' => ['required', 'date_format:Y-m', 'after_or_equal:1900-01', 'before_or_equal:'.now('America/La_Paz')->format('Y-m')]], ['month.*' => 'Selecciona un mes válido, desde enero de 1900 hasta el mes actual.'])->validate();

        return app(ReportPeriod::class)->resolve('month', $month.'-01');
    }

    public function detail(User $actor, string $module, string $month, string $source, int $page = 1, int $perPage = 5): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        $definition = app(ModuleReportDefinition::class)->get($module);
        abort_unless(in_array($source, $definition['sources'], true), 403);
        $period = $this->period($actor, $month);

        return app(DailyReportRecordsQuery::class)->records($actor, $period['start'], 'collections', $source, $page, $perPage, $period['end']);
    }
}
