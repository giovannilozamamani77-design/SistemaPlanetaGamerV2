<?php

namespace App\Filament\Resources\Incidencias\GlobalRecoveries;

use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Filament\Resources\Incidencias\GlobalRecoveries\Pages\ManageGlobalRecoveries;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GlobalRecoveryResource extends Resource
{
    protected static ?string $model = GlobalRecovery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Reposiciones de caja';

    protected static ?string $modelLabel = 'reposición global';

    protected static ?string $pluralModelLabel = 'Reposiciones';

    protected static ?int $navigationSort = 50;

    public static function table(Table $table): Table
    {
        return Tables\GlobalRecoveriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ManageGlobalRecoveries::route('/')];
    }
}
