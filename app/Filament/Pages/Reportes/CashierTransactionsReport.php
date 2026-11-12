<?php

namespace App\Filament\Pages\Reportes;

use App\Domain\Reportes\Data\CashierTransactionPeriod;
use App\Domain\Reportes\Queries\CashBoxReportQuery;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class CashierTransactionsReport extends CashBoxReport
{
    protected string $view = 'reportes.cashier-transactions';

    protected static ?string $title = 'Transacciones por cajera';

    protected static ?string $navigationLabel = 'Transacciones por cajera';

    protected static ?string $slug = 'reportes/transacciones-cajeras';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 3;

    #[Locked]
    public bool $transactionModalOpen = false;

    #[Locked]
    public string $transactionSource = 'BCP';

    #[Locked]
    public array $transactionFilters = [];

    public string $transactionPeriod = 'today';

    public string $transactionFrom = '';

    public string $transactionUntil = '';

    public string $transactionCashier = '';

    public string $transactionBox = '';

    public function showTransactionDetails(string $source): void
    {
        Gate::authorize('viewDailyReports');
        abort_unless(in_array($source, ['BCP', 'OPERATIONS'], true), 404);
        $this->transactionSource = $source;
        $this->transactionPeriod = 'today';
        $this->transactionFrom = $this->appliedFilters['from'];
        $this->transactionUntil = $this->appliedFilters['until'];
        $this->transactionCashier = (string) ($this->appliedFilters['user_id'] ?? '');
        $this->transactionBox = (string) ($this->appliedFilters['box_id'] ?? '');
        $this->applyTransactionFilters();
        $this->transactionModalOpen = true;
        $this->dispatch('open-modal', id: 'cashier-transaction-details');
    }

    public function applyTransactionFilters(): void
    {
        Gate::authorize('viewDailyReports');
        try {
            $period = app(CashierTransactionPeriod::class)->resolve($this->transactionPeriod, $this->transactionFrom, $this->transactionUntil);
            $filters = app(CashBoxReportQuery::class)->filters(auth()->user(), [...$period, 'user_id' => $this->transactionCashier, 'box_id' => $this->transactionBox]);
        } catch (ValidationException $exception) {
            $fields = ['period' => 'transactionPeriod', 'rangeFrom' => 'transactionFrom', 'rangeUntil' => 'transactionUntil', 'from' => 'transactionFrom', 'until' => 'transactionUntil', 'user_id' => 'transactionCashier', 'box_id' => 'transactionBox'];
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$fields[$field] ?? $field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->transactionFilters = $filters;
        $this->resetPage('transactionOperationsPage');
        $this->resetPage('transactionCountersPage');
        $this->resetValidation(['transactionPeriod', 'transactionFrom', 'transactionUntil', 'transactionCashier', 'transactionBox']);
    }

    public function closeTransactionDetails(): void
    {
        Gate::authorize('viewDailyReports');
        $this->transactionModalOpen = false;
        $this->dispatch('close-modal', id: 'cashier-transaction-details');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('boxes')->label('Volver a Reporte de Cajas')->icon('heroicon-o-arrow-left')->url(CashBoxReport::getUrl())];
    }

    protected function getViewData(): array
    {
        $data = $this->cashViewData();
        $detail = $this->transactionModalOpen ? app(CashBoxReportQuery::class)->execute(auth()->user(), $this->transactionFilters, [
            'operations' => $this->getPage('transactionOperationsPage'), 'counters' => $this->getPage('transactionCountersPage'),
        ]) : null;
        if ($detail) {
            $detail['operation_records']->setPageName('transactionOperationsPage');
            $detail['counter_records']->setPageName('transactionCountersPage');
        }

        return [...$data, 'transactionDetails' => $detail];
    }
}
