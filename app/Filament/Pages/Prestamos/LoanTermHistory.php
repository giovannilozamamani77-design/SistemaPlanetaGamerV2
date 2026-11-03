<?php

namespace App\Filament\Pages\Prestamos;

use App\Domain\Prestamos\Queries\LoanQuery;
use App\Filament\Support\Prestamos\LoanRenewalHistoryTable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;

class LoanTermHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'prestamos/{loan}/renovaciones';

    protected static ?string $title = 'Renovaciones y ampliaciones';

    protected string $view = 'prestamos.term-history';

    #[Locked]
    public int $loanId;

    public static function canAccess(): bool
    {
        return LoanList::canAccess();
    }

    public function mount(int $loan): void
    {
        app(LoanQuery::class)->loans(auth()->user())->whereKey($loan)->firstOrFail();
        $this->loanId = $loan;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('Préstamos')->url(LoanList::getUrl())];
    }

    public function table(Table $table): Table
    {
        return LoanRenewalHistoryTable::configure($table, $this->loanId);
    }
}
