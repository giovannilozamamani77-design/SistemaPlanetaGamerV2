<?php

namespace App\Filament\Resources\ControlHorario\TimeIncidents;

use App\Domain\ControlHorario\Models\TimeIncident;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TimeIncidentResource extends Resource
{
    protected static ?string $model = TimeIncident::class;

    protected static ?string $pluralModelLabel = 'Retrasos y faltas del personal';

    protected static string|\UnitEnum|null $navigationGroup = 'Personal';

    protected static ?string $navigationLabel = 'Retrasos y faltas';

    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function table(Table $table): Table
    {
        return Tables\TimeIncidentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTimeIncidents::route('/')];
    }
}
