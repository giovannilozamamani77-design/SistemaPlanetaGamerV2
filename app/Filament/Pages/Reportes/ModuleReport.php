<?php

namespace App\Filament\Pages\Reportes;

use App\Domain\Reportes\Data\ModuleReportDefinition;
use App\Domain\Reportes\Queries\ModuleReportQuery;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

abstract class ModuleReport extends Page
{
    use WithPagination;

    protected string $view = 'reportes.module-report';

    protected static string|\UnitEnum|null $navigationGroup = 'Reportes';

    protected const MODULE = '';

    public string $month = '';

    #[Locked]
    public string $appliedMonth = '';

    #[Locked]
    public bool $detailOpen = false;

    #[Locked]
    public string $detailSource = '';

    public int $detailPerPage = 5;

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::allows('viewDailyReports');
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        Gate::authorize('viewDailyReports');
        $this->month = $this->appliedMonth = now('America/La_Paz')->format('Y-m');
    }

    public function applyMonth(): void
    {
        app(ModuleReportQuery::class)->period(auth()->user(), $this->month);
        $this->resetErrorBag();
        $this->appliedMonth = $this->month;
        $this->closeDetails();
    }

    public function showDetails(string $source): void
    {
        Gate::authorize('viewDailyReports');
        $definition = app(ModuleReportDefinition::class)->get(static::MODULE);
        abort_unless(in_array($source, $definition['sources'], true), 403);
        $this->detailSource = $source;
        $this->detailOpen = true;
        $this->resetPage('reportRecordsPage');
        $this->dispatch('open-modal', id: 'module-report-records');
    }

    public function closeDetails(): void
    {
        Gate::authorize('viewDailyReports');
        $this->detailOpen = false;
        $this->resetPage('reportRecordsPage');
        $this->dispatch('close-modal', id: 'module-report-records');
    }

    public function updatedDetailPerPage(): void
    {
        Gate::authorize('viewDailyReports');
        $this->resetPage('reportRecordsPage');
        if (! in_array($this->detailPerPage, [5, 10, 25], true)) {
            $this->detailPerPage = 5;
            throw ValidationException::withMessages(['detailPerPage' => 'Elige 5, 10 o 25 registros.']);
        }
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('exportModulePdf')->label('Exportar PDF')->icon('heroicon-o-document-arrow-down')->url(fn () => $this->moduleExportUrl('pdf')),
            Action::make('exportModuleExcel')->label('Exportar Excel')->icon('heroicon-o-table-cells')->url(fn () => $this->moduleExportUrl('xlsx')),
            Action::make('summary')->label('Resumen general')->icon('heroicon-o-chart-bar-square')->url(DailyReport::getUrl()),
            Action::make('monthlyClose')->label('Cierre mensual')->icon('heroicon-o-lock-closed')->url(MonthlyReport::getUrl())];
    }

    public function moduleExportUrl(string $format): string
    {
        Gate::authorize('viewDailyReports');
        app(ModuleReportQuery::class)->period(auth()->user(), $this->appliedMonth);

        return route('reportes.modulo-export', ['module' => static::MODULE, 'format' => $format, 'month' => $this->appliedMonth]);
    }

    protected function getViewData(): array
    {
        $query = app(ModuleReportQuery::class);
        $report = $query->execute(auth()->user(), static::MODULE, $this->appliedMonth);
        $detail = $this->detailOpen ? $query->detail(auth()->user(), static::MODULE, $this->appliedMonth, $this->detailSource, $this->getPage('reportRecordsPage'), $this->detailPerPage) : null;

        return compact('report', 'detail');
    }
}
