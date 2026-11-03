<?php

namespace App\Filament\Pages\Prestamos;

use App\Domain\Prestamos\Queries\LoanQuery;
use App\Filament\Support\Prestamos\LoanAnnulmentActions;
use App\Filament\Support\Prestamos\LoanDetailActions;
use App\Filament\Support\Prestamos\LoanRecoveryActions;
use App\Filament\Support\Prestamos\LoanSettlementActions;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class LoanRecord extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'prestamos/{loan}/detalle';

    protected static ?string $title = 'Registro del préstamo';

    protected string $view = 'prestamos.loan-record';

    #[Locked]
    public int $loanId;

    public static function canAccess(): bool
    {
        return LoanList::canAccess();
    }

    public function mount(int $loan): void
    {
        app(LoanQuery::class)->detail(auth()->user(), $loan);
        $this->loanId = $loan;
    }

    protected function getViewData(): array
    {
        return ['detail' => app(LoanQuery::class)->detail(auth()->user(), $this->loanId)];
    }

    protected function getHeaderActions(): array
    {
        return [LoanDetailActions::history()->record(fn () => app(LoanQuery::class)->loans(auth()->user())->findOrFail($this->loanId)), LoanRecoveryActions::expense()->record(fn () => app(LoanQuery::class)->loans(auth()->user())->findOrFail($this->loanId)), LoanRecoveryActions::sale()->record(fn () => app(LoanQuery::class)->loans(auth()->user())->findOrFail($this->loanId)), LoanAnnulmentActions::make()->record(fn () => app(LoanQuery::class)->loans(auth()->user())->findOrFail($this->loanId)), LoanSettlementActions::make()->record(fn () => app(LoanQuery::class)->loans(auth()->user())->findOrFail($this->loanId)), Action::make('list')->label('Préstamos registrados')->url(LoanList::getUrl()), Action::make('print')->label('Imprimir recibo vigente')->visible(fn () => app(LoanQuery::class)->detail(auth()->user(), $this->loanId)['status'] !== 'ANULADO')->icon('heroicon-o-printer')->url(fn () => route('prestamos.recibo-pdf', ['loan' => $this->loanId, 'print' => 1]))->openUrlInNewTab(), Action::make('originalReceipt')->label('Recibo original')->color('gray')->url(fn () => route('prestamos.recibo-pdf', ['loan' => $this->loanId, 'original' => 1]))->openUrlInNewTab()];
    }
}
