<?php

namespace App\Filament\Resources\Cajas\CashBoxes\Schemas;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Queries\CashBoxTypeCatalog;
use App\Support\Bolivianos;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;

class CashBoxForm
{
    public static function types(): array
    {
        return app(CashBoxTypeCatalog::class)->options();
    }

    public static function create(): array
    {
        return [
            Section::make('Identificación y capital')->description('El capital base es una referencia. Este registro no ingresa dinero ni abre una sesión.')
                ->columns(['default' => 1, 'md' => 2])->columnSpanFull()->schema([
                    TextInput::make('code')->label('Código único')->placeholder('CAJA-BCP-01')->helperText('Letras, números, guion o guion bajo.')->required()->maxLength(40),
                    TextInput::make('name')->label('Nombre de la caja')->required()->maxLength(120),
                    Select::make('type')->label('Tipo de caja')->options(fn (): array => app(CashBoxTypeCatalog::class)->options(true))->required()->helperText('La caja recibirá los componentes predeterminados del tipo seleccionado.'),
                    TextInput::make('base_capital')->label('Capital base')->prefix('Bs')->inputMode('decimal')->required()->helperText('Usa punto para los centavos; ejemplo: 20000.00.'),
                    Toggle::make('is_active')->label('Caja activa')->default(true),
                ]),
            self::confirmation(),
        ];
    }

    public static function edit(): array
    {
        return [
            Section::make('Referencia de la caja')->columns(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                TextEntry::make('box_code')->label('Código')->state(fn (CashBox $record): string => $record->code),
                TextEntry::make('box_type')->label('Tipo')->state(fn (CashBox $record): string => self::types()[$record->type]),
                TextEntry::make('box_capital')->label('Capital base')->state(fn (CashBox $record): string => 'Bs '.Bolivianos::format($record->base_capital_cents)),
            ]),
            Section::make('Nombre y disponibilidad')->description('Desactivar conserva el registro y su historial.')
                ->columns(['default' => 1, 'md' => 2])->columnSpanFull()->schema([
                    TextInput::make('name')->label('Nombre de la caja')->required()->maxLength(120),
                    Toggle::make('is_active')->label('Caja activa'),
                    Hidden::make('expected_revision'),
                ]),
            self::confirmation(),
        ];
    }

    private static function confirmation(): Section
    {
        return Section::make('Confirmación administrativa')->description('Tu sesión administrativa autoriza este cambio. El motivo se conserva en el historial.')
            ->columnSpanFull()->schema([
                Textarea::make('reason')->label('Motivo del registro o cambio')->required()->minLength(3)->maxLength(2000)->rows(3),
                Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
            ]);
    }
}
