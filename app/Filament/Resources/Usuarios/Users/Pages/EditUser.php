<?php

namespace App\Filament\Resources\Usuarios\Users\Pages;

use App\Domain\Usuarios\Actions\SetUserPinAction;
use App\Filament\Resources\Usuarios\Users\Schemas\UserForm;
use App\Filament\Resources\Usuarios\Users\UserResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setPin')->label('Configurar PIN')
                ->visible(fn (): bool => auth()->user()->hasRole('administrador') && auth()->user()->can('usuarios.pin.administrar'))
                ->schema([
                    TextInput::make('pin')->label('Nuevo PIN')->password()->required()->regex('/\A[0-9]{4}\z/')->helperText('Exactamente 4 dígitos.')->same('pinConfirmation'),
                    TextInput::make('pinConfirmation')->label('Confirmar PIN')->password()->required(),
                ])->action(function (#[SensitiveParameter] array $data): void {
                    app(SetUserPinAction::class)->execute(auth()->user(), $this->record, $data['pin']);
                    Notification::make()->title('PIN actualizado')->success()->send();
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $roles = $this->record->roles()->pluck('name')->all();
        $data['roles'] = count($roles) === 1 ? $roles[0] : null;
        $data['permissions'] = $this->record->permissions()->pluck('name')->all();
        $data['restrictions'] = $this->record->restrictedPermissions()->pluck('name')->all();
        unset($data['password'],$data['pin_hash']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return UserForm::save(auth()->user(), $data, $record);
    }
}
