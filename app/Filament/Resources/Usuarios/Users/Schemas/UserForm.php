<?php

namespace App\Filament\Resources\Usuarios\Users\Schemas;

use App\Domain\Usuarios\Actions\SaveUserAction;
use App\Domain\Usuarios\Support\Username;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserForm
{
    /** @param array<string, mixed> $data */
    public static function save(User $actor, #[SensitiveParameter] array $data, ?User $record = null, string $statePath = 'data'): User
    {
        try {
            return app(SaveUserAction::class)->execute($actor, $data, $record);
        } catch (ValidationException $error) {
            $errors = $error->errors();
            foreach (['username', 'data.username'] as $key) {
                if (array_key_exists($key, $errors)) {
                    $messages = $errors[$key];
                    unset($errors[$key]);
                    $errors[$statePath.'.username'] = $messages;
                }
            }
            throw ValidationException::withMessages($errors);
        }
    }

    public static function configure(Schema $schema): Schema
    {
        $permissionOptions = fn (): array => Permission::where('guard_name', 'web')->orderBy('name')->pluck('name', 'name')->all();

        return $schema->components([
            Section::make('Datos de la cuenta')->description('Información necesaria para ingresar al sistema.')
                ->icon('heroicon-o-user-circle')->columns(2)->columnSpanFull()->schema([
                    TextInput::make('name')->label('Nombre completo')->required()->maxLength(255)->columnSpanFull()
                        ->live(onBlur: true)->afterStateUpdated(function (?string $state, Get $get, Set $set, string $operation): void {
                            if ($operation === 'create' && blank($get('username'))) {
                                $set('username', Username::availableFromName($state ?? ''));
                            }
                        }),
                    TextInput::make('username')->label('Nombre de usuario')->maxLength(64)->required()
                        ->mutateStateForValidationUsing(fn (?string $state, Get $get, ?User $record): ?string => filled($state) ? trim($state) : ($record?->username ?? Username::availableFromName($get('name') ?? '')))
                        ->rules([fn (?User $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                            if (User::where('username_key', Username::key($value))->when($record, fn ($query) => $query->where('id', '!=', $record->id))->exists()) {
                                $fail('Este nombre de usuario ya existe. Asigna uno diferente.');
                            }
                        }])
                        ->regex('/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/')
                        ->helperText('Se genera desde el primer nombre. Si ya existe, añade 1, 2, 3… Puedes editarlo; debe ser único.'),
                    TextInput::make('email')->label('Correo electrónico')->email()->required()->unique(ignoreRecord: true),
                    TextInput::make('password')->label('Contraseña')->password()->revealable()->minLength(8)
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (string $operation): string => $operation === 'create' ? 'Mínimo 8 caracteres.' : 'Vacía para conservar la contraseña actual.'),
                ]),
            Section::make('PIN personal')->description('Administración puede configurar o restablecer el PIN de esta cuenta. Nunca se muestra el PIN actual.')
                ->icon('heroicon-o-key')->columns(2)->columnSpanFull()
                ->visible(fn (): bool => auth()->user()?->hasRole('administrador') && auth()->user()->can('usuarios.pin.administrar'))
                ->schema([
                    TextInput::make('pin')->label('Nuevo PIN')->password()->autocomplete('new-password')
                        ->regex('/\A[0-9]{4}\z/')->same('pin_confirmation')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (string $operation): string => $operation === 'create' ? 'Opcional: exactamente 4 dígitos. También puedes configurarlo después.' : 'Deja vacío para conservar el PIN actual. Exactamente 4 dígitos.'),
                    TextInput::make('pin_confirmation')->label('Confirmar nuevo PIN')->password()->autocomplete('new-password')
                        ->requiredWith('pin')->dehydrated(fn (?string $state): bool => filled($state)),
                ]),
            Section::make('Rol y acceso')->description('Selecciona un único rol existente. Sus permisos se configuran en Administración → Roles.')
                ->icon('heroicon-o-shield-check')->columnSpanFull()->schema([
                    Select::make('roles')->label('Rol de acceso')->placeholder('Selecciona un rol')->required()->searchable()->preload()
                        ->formatStateUsing(fn (mixed $state): mixed => is_array($state) ? (count($state) === 1 ? reset($state) : null) : $state)
                        ->helperText('Cada usuario tiene un solo rol. Cambiarlo reemplaza el anterior.')
                        ->options(fn (): array => Role::where('guard_name', 'web')->orderBy('name')->pluck('name', 'name')->all()),
                    TextInput::make('salary_pay_day')->label('Día de pago mensual')->integer()->minValue(1)->maxValue(31)->helperText('Opcional hasta configurar. Define el corte de periodos; no borra pendientes ni cambia el día libre calendario.'),
                    Toggle::make('is_active')->label('Cuenta activa')->default(true)
                        ->helperText('Una cuenta inactiva conserva su historial y no puede ingresar.'),
                ]),
            Section::make('Excepciones individuales')->description('Opcional. Para un acceso habitual, configura el rol en lugar de repetir permisos por persona.')
                ->collapsible()->collapsed()->visible(fn (string $operation): bool => $operation === 'edit')->columnSpanFull()->schema([
                    Select::make('permissions')->label('Permisos adicionales')->multiple()->default([])->options($permissionOptions)->dehydratedWhenHidden(),
                    Select::make('restrictions')->label('Permisos bloqueados')->helperText('Un bloqueo prevalece sobre el rol y los permisos adicionales.')
                        ->multiple()->default([])->options($permissionOptions)->dehydratedWhenHidden(),
                ]),
        ]);
    }
}
