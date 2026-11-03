<?php

namespace App\Livewire\Prestamos;

use App\Domain\Prestamos\Queries\LoanQuery;
use App\Filament\Support\Prestamos\LoanRenewalHistoryTable;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class LoanRenewalHistory extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    #[Locked]
    public int $loanId;

    public function mount(int $loanId): void
    {
        abort_unless(auth()->check(), 403);
        app(LoanQuery::class)->loans(auth()->user())->findOrFail($loanId);
        $this->loanId = $loanId;
    }

    public function table(Table $table): Table
    {
        return LoanRenewalHistoryTable::configure($table, $this->loanId)->queryStringIdentifier('loan-'.$this->loanId.'-renewals');
    }

    #[On('loan-term-changed')]
    public function refreshHistory(int $loanId): void
    {
        abort_unless(auth()->check(), 403);
        app(LoanQuery::class)->loans(auth()->user())->findOrFail($this->loanId);
        if ($loanId === $this->loanId) {
            $this->resetPage($this->getTablePaginationPageName());
        }
    }

    public function render(): View
    {
        abort_unless(auth()->check(), 403);
        app(LoanQuery::class)->loans(auth()->user())->findOrFail($this->loanId);

        return view('prestamos.renewal-history');
    }
}
