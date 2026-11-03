<?php

namespace App\Filament\Resources\Liquidaciones\History\Pages;

use App\Filament\Pages\Liquidaciones\LiquidationSettings;
use App\Filament\Resources\Liquidaciones\History\LiquidationHistoryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListLiquidations extends ListRecords
{
    protected static string $resource = LiquidationHistoryResource::class;

    protected string $view = 'liquidaciones.history';

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('settings')->label('Configuración')->icon('heroicon-o-cog-6-tooth')->iconButton()->tooltip('Configuración de liquidaciones')
            ->url(LiquidationSettings::getUrl())->visible(fn (): bool => LiquidationSettings::canAccess())];
    }
}
