<?php

namespace App\Filament\Resources\Usuarios\Roles;

use App\Filament\Resources\Usuarios\Roles\Pages\ManageRoles;
use App\Filament\Resources\Usuarios\Roles\Schemas\RoleForm;
use App\Filament\Resources\Usuarios\Roles\Tables\RolesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?string $modelLabel = 'rol';

    protected static ?string $pluralModelLabel = 'Roles';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
