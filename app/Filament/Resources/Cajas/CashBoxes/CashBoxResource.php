<?php

namespace App\Filament\Resources\Cajas\CashBoxes;

use App\Domain\Cajas\Models\CashBox;
use App\Filament\Resources\Cajas\CashBoxes\Pages\ListCashBoxes;
use App\Filament\Resources\Cajas\CashBoxes\Schemas\CashBoxForm;
use App\Filament\Resources\Cajas\CashBoxes\Tables\CashBoxesTable;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CashBoxResource extends Resource
{
    protected static ?string $model = CashBox::class;

    protected static ?string $modelLabel = 'caja';

    protected static ?string $pluralModelLabel = 'Configuración de cajas';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuraciones';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Configuración de cajas';

    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return static::getRouteBaseName().'.index';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(CashBoxForm::create());
    }

    public static function table(Table $table): Table
    {
        return CashBoxesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withExists('configurations')->withExists(['sessions as has_open_session' => fn (Builder $query) => $query->where('status', 'ABIERTA')->whereNotNull('active_slot')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCashBoxes::route('/')];
    }
}
