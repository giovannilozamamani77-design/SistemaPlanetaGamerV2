<?php

namespace App\Filament\Resources\Usuarios\Roles\Tables;

use App\Domain\Usuarios\Actions\SaveRoleAction;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->where('guard_name', 'web')->withCount('permissions')
            ->withCount(['users as assigned_users_count' => fn (Builder $query): Builder => $query->where('model_type', (new User)->getMorphClass())]))
            ->columns([Stack::make([
                TextColumn::make('name')->label('Rol')->searchable()->sortable()->weight('bold')->icon(Heroicon::OutlinedShieldCheck),
                TextColumn::make('permissions_count')->label('Permisos')->formatStateUsing(fn (int $state): string => $state.' permisos habilitados'),
                TextColumn::make('assigned_users_count')->label('Usuarios')->formatStateUsing(fn (int $state): string => $state.' usuarios asignados')->color('gray'),
            ])->space(3)])
            ->contentGrid(['md' => 2, 'xl' => 3])->paginated([6, 12, 24])->defaultSort('id')
            ->recordActions([
                EditAction::make()->label('Configurar permisos')->modalHeading('Configurar permisos del rol')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar permisos')->modalCancelActionLabel('Cancelar')->modalWidth('3xl')
                    ->mutateRecordDataUsing(function (array $data, Role $record): array {
                        $data['permissions'] = $record->permissions()->pluck('name')->all();

                        return $data;
                    })
                    ->using(fn (Role $record, array $data): Role => app(SaveRoleAction::class)->execute(auth()->user(), $data, $record)),
            ]);
    }
}
