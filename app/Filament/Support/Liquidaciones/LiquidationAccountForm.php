<?php

namespace App\Filament\Support\Liquidaciones;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

class LiquidationAccountForm
{
    public static function fields(): array
    {
        return [Section::make('Cuenta de destino')->description('Guardar conserva una versión. Después elige esta cuenta en la configuración de cada caja.')->schema([
            TextInput::make('name')->label('Nombre de la cuenta')->placeholder('Ej. Comisiones principal')->required()->maxLength(120),
            TextInput::make('holder_name')->label('Titular de la cuenta')->required()->maxLength(120),
            TextInput::make('destination')->label('Número de cuenta o billetera')->required()->minLength(3)->maxLength(120),
        ])->columns(2), Section::make('QR y confirmación')->schema([
            FileUpload::make('qr_path')->label('QR de la cuenta de destino')->disk('local')->visibility('private')->directory(fn (): string => 'cash-box-commission-qr/'.auth()->id())
                ->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->maxSize(4096)->previewable(false)->helperText('Si cambias el número de destino, sube su nuevo QR o retira la imagen anterior.'),
            Textarea::make('reason')->label('Motivo del cambio')->required()->minLength(3)->maxLength(2000),
            TextInput::make('pin')->label('PIN de Administración')->password()->required()->length(4)->autocomplete('off'),
            Hidden::make('account_id'), Hidden::make('request_key'),
        ])];
    }
}
