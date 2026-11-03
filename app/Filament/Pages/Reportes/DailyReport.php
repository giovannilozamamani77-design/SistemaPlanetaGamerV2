<?php

namespace App\Filament\Pages\Reportes;

use App\Domain\Reportes\Data\ReportPeriod;
use App\Domain\Reportes\Queries\DailyReportQuery;
use App\Domain\Reportes\Queries\DailyReportRecordsQuery;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class DailyReport extends Page
{
    use WithPagination;

    protected string $view = 'reportes.daily-report';

    protected static ?string $title = 'Resumen diario';

    protected static ?string $navigationLabel = 'Resumen general';

    protected static string|\UnitEnum|null $navigationGroup = 'Reportes';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $slug = 'reportes';

    protected static ?int $navigationSort = 1;

    public string $date = '';

    public string $periodMode = 'day';

    public string $rangeFrom = '';

    public string $rangeUntil = '';

    #[Locked]
    public string $appliedMode = 'day';

    #[Locked]
    public string $appliedRangeFrom = '';

    #[Locked]
    public string $appliedRangeUntil = '';

    #[Locked]
    public string $appliedDate = '';

    #[Locked]
    public bool $detailOpen = false;

    #[Locked]
    public string $detailMetric = 'collections';

    #[Locked]
    public string $detailSource = 'ALL';

    public int $detailPerPage = 5;

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::allows('viewDailyReports');
    }

    public function getTitle(): string
    {
        return $this->appliedMode === 'day' ? 'Resumen diario' : 'Resumen por periodo';
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        Gate::authorize('viewDailyReports');
        $this->date = now('America/La_Paz')->toDateString();
        $this->rangeFrom = $this->rangeUntil = $this->date;
        $this->appliedDate = $this->date;
        $this->appliedRangeFrom = $this->appliedRangeUntil = $this->date;
    }

    public function applyDate(): void
    {
        Gate::authorize('viewDailyReports');
        $period = app(ReportPeriod::class)->resolve($this->periodMode, $this->date, $this->rangeFrom, $this->rangeUntil);
        $this->resetErrorBag();
        $this->appliedDate = $this->periodMode === 'range' ? $period['start'] : $this->date;
        $this->appliedMode = $this->periodMode;
        $this->appliedRangeFrom = $period['start'];
        $this->appliedRangeUntil = $period['end'];
        $this->closeDetails();
    }

    public function showDetails(string $metric = 'collections', string $source = 'ALL'): void
    {
        Gate::authorize('viewDailyReports');
        $period = app(ReportPeriod::class)->resolve($this->appliedMode, $this->appliedDate, $this->appliedRangeFrom, $this->appliedRangeUntil);
        app(DailyReportRecordsQuery::class)->context(auth()->user(), $period['start'], $metric, $source, $period['end']);
        $this->detailMetric = $metric;
        $this->detailSource = $source;
        $this->resetPage('reportRecordsPage');
        $this->detailOpen = true;
        $this->dispatch('open-modal', id: 'daily-report-records');
    }

    public function closeDetails(): void
    {
        Gate::authorize('viewDailyReports');
        $this->detailOpen = false;
        $this->resetPage('reportRecordsPage');
        $this->dispatch('close-modal', id: 'daily-report-records');
    }

    public function updatedDetailPerPage(): void
    {
        Gate::authorize('viewDailyReports');
        $this->resetPage('reportRecordsPage');
        if (! in_array($this->detailPerPage, [5, 10, 25], true)) {
            $this->detailPerPage = 5;
            throw ValidationException::withMessages(['detailPerPage' => 'Elige 5, 10 o 25 registros por página.']);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')->label('Exportar PDF')->icon('heroicon-o-arrow-down-tray')->url(fn (): string => $this->exportUrl('reportes.resumen-pdf')),
            Action::make('exportExcel')->label('Exportar Excel')->icon('heroicon-o-table-cells')->url(fn (): string => $this->exportUrl('reportes.resumen-excel')),
            Action::make('exportCsv')->label('Exportar CSV')->icon('heroicon-o-document-text')->url(fn (): string => $this->exportUrl('reportes.resumen-csv')),
            Action::make('monthlyClose')->label('Cierre mensual')->icon('heroicon-o-lock-closed')->url(MonthlyReport::getUrl()),
        ];
    }

    protected function exportUrl(string $route): string
    {
        Gate::authorize('viewDailyReports');
        $period = app(ReportPeriod::class)->resolve($this->appliedMode, $this->appliedDate, $this->appliedRangeFrom, $this->appliedRangeUntil);

        return route($route, ['mode' => $this->appliedMode, 'date' => $this->appliedDate,
            'from' => $this->appliedRangeFrom, 'until' => $this->appliedRangeUntil,
            'expected_start' => $period['start'], 'expected_end' => $period['end']]);
    }

    public function detailExportUrl(string $format): string
    {
        Gate::authorize('viewDailyReports');
        abort_unless($this->detailOpen && in_array($format, ['xlsx', 'csv'], true), 404);
        $period = app(ReportPeriod::class)->resolve($this->appliedMode, $this->appliedDate, $this->appliedRangeFrom, $this->appliedRangeUntil);

        return route('reportes.detalle-export', ['format' => $format, 'mode' => $this->appliedMode, 'date' => $this->appliedDate,
            'from' => $this->appliedRangeFrom, 'until' => $this->appliedRangeUntil, 'expected_start' => $period['start'], 'expected_end' => $period['end'],
            'metric' => $this->detailMetric, 'source' => $this->detailSource]);
    }

    protected function getViewData(): array
    {
        return ['report' => app(DailyReportQuery::class)->execute(auth()->user(), $this->appliedDate, $this->detailOpen ? ['metric' => $this->detailMetric, 'source' => $this->detailSource, 'page' => $this->getPage('reportRecordsPage'), 'per_page' => $this->detailPerPage] : null, ['mode' => $this->appliedMode, 'from' => $this->appliedRangeFrom, 'until' => $this->appliedRangeUntil])];
    }
}
