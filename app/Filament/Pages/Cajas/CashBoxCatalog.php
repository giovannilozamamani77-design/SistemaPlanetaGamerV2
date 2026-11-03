<?php

namespace App\Filament\Pages\Cajas;

class CashBoxCatalog extends CashBoxOperations
{
    protected static ?string $title = 'Cajas';

    protected static ?string $navigationLabel = 'Cajas';

    protected static ?int $navigationSort = -10;

    protected static ?string $slug = 'cajas';

    public function mount(): void
    {
        if (request()->integer('cashBox')) {
            parent::mount();
        }
    }
}
