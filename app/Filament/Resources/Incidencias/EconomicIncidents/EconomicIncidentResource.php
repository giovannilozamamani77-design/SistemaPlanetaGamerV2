<?php

namespace App\Filament\Resources\Incidencias\EconomicIncidents;

use App\Domain\Incidencias\Models\EconomicIncident;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EconomicIncidentResource extends Resource
{
    protected static ?string $model = EconomicIncident::class;

    protected static ?string $pluralModelLabel = 'Faltantes del personal';

    protected static string|\UnitEnum|null $navigationGroup = 'Reposiciones de caja';

    protected static ?string $navigationLabel = 'Faltantes';

    protected static ?int $navigationSort = 40;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function table(Table $table): Table
    {
        return Tables\EconomicIncidentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEconomicIncidents::route('/')];
    }
}
