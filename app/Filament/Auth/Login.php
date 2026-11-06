<?php

namespace App\Filament\Auth;

use App\Domain\Usuarios\Support\Username;
use Filament\Actions\Action;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use SensitiveParameter;

class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')->label('Correo o nombre de usuario')
            ->required()->maxLength(255)->autocomplete('username')->autofocus();
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $identifier = trim($data['email']);

        return [
            str_contains($identifier, '@') ? 'email' : 'username_key' => str_contains($identifier, '@') ? $identifier : Username::key($identifier),
            'password' => $data['password'],
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return '';
    }

    public function getHeading(): string|Htmlable|null
    {
        return filled($this->userUndertakingMultiFactorAuthentication) ? parent::getHeading() : null;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return filled($this->userUndertakingMultiFactorAuthentication) ? parent::getSubheading() : null;
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()->label('Ingresar al sistema');
    }
}
