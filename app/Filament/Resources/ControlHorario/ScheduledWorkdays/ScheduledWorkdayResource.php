<?php

namespace App\Filament\Resources\ControlHorario\ScheduledWorkdays;

use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\Pages\CreateScheduledWorkday;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\Pages\EditScheduledWorkday;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\Pages\ListScheduledWorkdays;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\Schemas\ScheduledWorkdayForm;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\Tables\ScheduledWorkdaysTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ScheduledWorkdayResource extends Resource
{
    protected static ?string $model = ScheduledWorkday::class;

    protected static ?string $modelLabel = 'turno de un día';

    protected static ?string $pluralModelLabel = 'Historial diario y excepciones';

    protected static string|\UnitEnum|null $navigationGroup = 'Personal';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function form(Schema $schema): Schema
    {
        return ScheduledWorkdayForm::configure($schema);
    }

    protected static ?string $navigationLabel = 'Historial diario y excepciones';

    protected static ?int $navigationSort = 10;

    protected static bool $shouldRegisterNavigation = false;

    public static function table(Table $table): Table
    {
        return ScheduledWorkdaysTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduledWorkdays::route('/'),
            'create' => CreateScheduledWorkday::route('/create'),
            'edit' => EditScheduledWorkday::route('/{record}/edit'),
        ];
    }
}
