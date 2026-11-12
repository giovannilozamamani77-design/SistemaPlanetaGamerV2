<?php

namespace App\Filament\Pages\Reportes;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Reportes\Queries\CashBoxReportQuery;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

class CashBoxReport extends ModuleReport
{
    protected const MODULE = 'BOXES';

    protected string $view = 'reportes.cash-box-report';

    public string $fromDate = '';

    public string $untilDate = '';

    public string $cashierId = '';

    public string $cashBoxId = '';

    #[Locked]
    public array $appliedFilters = [];

    public function mount(): void
    {
        parent::mount();
        $this->fromDate = now('America/La_Paz')->startOfMonth()->toDateString();
        $this->untilDate = now('America/La_Paz')->toDateString();
        $this->applyCashFilters();
    }

    public function applyCashFilters(): void
    {
        $filters = app(CashBoxReportQuery::class)->filters(auth()->user(), [
            'from' => $this->fromDate, 'until' => $this->untilDate, 'user_id' => $this->cashierId, 'box_id' => $this->cashBoxId,
        ]);
        $this->appliedFilters = $filters;
        foreach (['cashOperationsPage', 'cashCountersPage', 'cashShortagesPage'] as $page) {
            $this->resetPage($page);
        }
        $this->resetErrorBag();
    }

    protected function getHeaderActions(): array
    {
        $actions = parent::getHeaderActions();
        $actions[] = Action::make('cashierTransactions')->label('Transacciones por cajera')->icon('heroicon-o-arrows-right-left')->url(CashierTransactionsReport::getUrl());
        $actions[0]->label('PDF del resumen mensual');
        $actions[1]->label('Excel del resumen mensual');

        return $actions;
    }

    protected function getViewData(): array
    {
        return [...parent::getViewData(), ...$this->cashViewData()];
    }

    protected function cashViewData(): array
    {
        Gate::authorize('viewDailyReports');

        return [
            'cashReport' => app(CashBoxReportQuery::class)->execute(auth()->user(), $this->appliedFilters, [
                'operations' => $this->getPage('cashOperationsPage'), 'counters' => $this->getPage('cashCountersPage'), 'shortages' => $this->getPage('cashShortagesPage'),
            ]),
            'cashiers' => User::orderBy('name')->pluck('name', 'id'),
            'boxes' => CashBox::orderBy('name')->pluck('name', 'id'),
        ];
    }

    protected static ?string $title = 'Reporte de Cajas';

    protected static ?string $navigationLabel = 'Cajas';

    protected static ?string $slug = 'reportes/cajas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 2;
}
