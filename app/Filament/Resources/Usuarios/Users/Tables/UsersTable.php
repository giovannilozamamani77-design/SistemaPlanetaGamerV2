<?php

namespace App\Filament\Resources\Usuarios\Users\Tables;

use App\Filament\Resources\Usuarios\Users\Schemas\UserForm;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with('roles')
            ->withCount(['serviceRatings', 'serviceRatings as good_ratings_count' => fn (Builder $query): Builder => $query->where('value', 'buena'), 'serviceRatings as neutral_ratings_count' => fn (Builder $query): Builder => $query->where('value', 'regular'), 'serviceRatings as bad_ratings_count' => fn (Builder $query): Builder => $query->where('value', 'mala')]))
            ->columns([
                Stack::make([
                    Split::make([
                        TextColumn::make('name')->label('Nombre')->searchable()->sortable()->weight('bold')->size('lg')
                            ->icon('heroicon-o-user-circle')->wrap(),
                        TextColumn::make('is_active')->label('Estado')->badge()->grow(false)
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Activo' : 'Inactivo')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                    ]),
                    TextColumn::make('username')->label('Usuario')->prefix('Usuario: ')->searchable()->placeholder('Sin asignar · acceso por correo')->icon('heroicon-o-at-symbol'),
                    TextColumn::make('email')->label('Correo')->searchable()->icon('heroicon-o-envelope')->color('gray')->wrap(),
                    TextColumn::make('roles.name')->label('Rol')->badge()->placeholder('Sin rol asignado'),
                    TextColumn::make('created_at')->label('Registro')->date('d/m/Y')
                        ->prefix('Registro: ')->color('gray')->sortable(),
                    TextColumn::make('service_ratings_count')->label('Atención al cliente')
                        ->icon('heroicon-o-face-smile')
                        ->formatStateUsing(fn (int $state): string => $state ? $state.' calificaciones' : 'Atención: sin calificaciones')
                        ->description(fn (User $record): ?string => $record->service_ratings_count
                            ? 'Buenas: '.$record->good_ratings_count.' · Regulares: '.$record->neutral_ratings_count.' · Malas: '.$record->bad_ratings_count
                            : 'La captura desde tablet se habilitará con Cajas.'),
                ])->space(3),
            ])
            ->contentGrid(['md' => 2, '2xl' => 3])
            ->filters([
                SelectFilter::make('roles')->label('Rol de acceso')->relationship('roles', 'name'),
                TernaryFilter::make('is_active')->label('Estado de cuenta')->trueLabel('Activos')->falseLabel('Inactivos'),
            ])
            ->recordUrl(null)->recordAction('edit')
            ->recordActions([
                EditAction::make()->label('Editar usuario')->icon('heroicon-o-pencil-square')->modal()
                    ->modalHeading('Editar usuario')->modalDescription('Actualiza sus datos, rol o PIN. Los campos vacíos de contraseña y PIN conservan los actuales.')
                    ->modalSubmitActionLabel('Guardar cambios')->modalCancelActionLabel('Cancelar')
                    ->modalWidth('2xl')->stickyModalHeader()->stickyModalFooter()
                    ->mutateRecordDataUsing(function (array $data, User $record): array {
                        $roles = $record->roles()->pluck('name')->all();
                        $data['roles'] = count($roles) === 1 ? $roles[0] : null;
                        $data['permissions'] = $record->permissions()->pluck('name')->all();
                        $data['restrictions'] = $record->restrictedPermissions()->pluck('name')->all();
                        unset($data['password'], $data['pin_hash'], $data['pin_changed_at'], $data['remember_token']);

                        return $data;
                    })
                    ->using(fn (User $record, array $data, Action $action): User => UserForm::save(auth()->user(), $data, $record, 'mountedActions.'.$action->getNestingIndex().'.data')),
            ])
            ->paginated([6, 12, 24])->defaultPaginationPageOption(6)->defaultSort('id', 'desc')
            ->searchPlaceholder('Buscar por nombre, usuario o correo')->extraAttributes(['class' => 'pg-user-cards']);
    }
}
