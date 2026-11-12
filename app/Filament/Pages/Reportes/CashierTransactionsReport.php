<?php

namespace App\Filament\Pages\Reportes;

use Filament\Actions\Action;

class CashierTransactionsReport extends CashBoxReport
{
    protected string $view = 'reportes.cashier-transactions';

    protected static ?string $title = 'Transacciones por cajera';

    protected static ?string $navigationLabel = 'Transacciones por cajera';

    protected static ?string $slug = 'reportes/transacciones-cajeras';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 3;

    protected function getHeaderActions(): array
    {
        return [Action::make('boxes')->label('Volver a Reporte de Cajas')->icon('heroicon-o-arrow-left')->url(CashBoxReport::getUrl())];
    }

    protected function getViewData(): array
    {
        return $this->cashViewData();
    }
}
