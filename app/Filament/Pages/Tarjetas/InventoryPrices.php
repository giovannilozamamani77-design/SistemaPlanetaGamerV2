<?php

namespace App\Filament\Pages\Tarjetas;

use App\Domain\Tarjetas\Models\CardProduct;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Gate;

class InventoryPrices extends StoreInventory
{
    protected string $view = 'tarjetas.inventory-prices';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Ajustes de precios';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return parent::canAccess() && Gate::allows('update', CardProduct::class);
    }

    protected function usesCardLayout(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [$this->wholesaleRulesAction(), Action::make('inventory')->label('Volver al inventario')->icon('heroicon-o-arrow-left')->color('gray')->url(StoreInventory::getUrl())];
    }
}
