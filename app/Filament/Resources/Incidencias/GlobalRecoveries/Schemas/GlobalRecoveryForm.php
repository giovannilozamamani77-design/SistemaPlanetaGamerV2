<?php

namespace App\Filament\Resources\Incidencias\GlobalRecoveries\Schemas;

use App\Models\User;
use App\Support\Bolivianos;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

class GlobalRecoveryForm
{
    public static function components(bool $admin = false): array
    {
        return [
            Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
            Select::make('user_id')->label('Cajera responsable')->options(fn (): array => User::role('cajera')->orderBy('name')->pluck('name', 'id')->all())->searchable()->required($admin)->visible($admin),
            TextInput::make('amount')->label('Importe total a devolver')->prefix('Bs')->required(),
            Select::make('method')->label('Medio')->options(['EFECTIVO' => 'Efectivo', 'DEPOSITO' => 'Depósito', 'QR' => 'QR', 'SUELDO' => 'Aplicación salarial real'])->required(),
            TextInput::make('operation_reference')->label('Operación bancaria')->helperText('Necesaria para depósito o QR.'),
            TextInput::make('destination')->label('Cuenta o destino'),
            TextInput::make('salary_period')->label('Mes de aplicación salarial (AAAA-MM)'),
            Textarea::make('note')->label('Constancia u observación')->default('Reposición global')->required()->maxLength(2000),
        ];
    }

    public static function data(array $data): array
    {
        $data['amount_cents'] = Bolivianos::cents($data['amount']);

        return $data;
    }
}
