<?php

namespace App\Filament\Support\Tarjetas;

use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;

class EntelRechargeForms
{
    public static function inline(): array
    {
        return [
            Select::make('kind')->label('Servicio')->options(EntelDigitalAccount::KINDS)->required(),
            CardInventoryForms::money('amount', 'Monto recargado'),
            Toggle::make('external_confirmed')->label('Verifiqué la recarga en Entel')->accepted()->required(),
            CardInventoryForms::key(),
        ];
    }
}
