<?php

namespace App\Filament\Resources\Cajas\Types\Schemas;

use App\Domain\Cajas\Data\CashBoxAccountSettings;
use App\Domain\Cajas\Queries\CashBoxTypeCatalog;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

class CashBoxTypeForm
{
    public static function fields(bool $editing = false): array
    {
        return [Section::make('Tipo de caja')->description('Una plantilla propone componentes. No contiene saldos ni modifica las cajas existentes.')
            ->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                TextInput::make('code')->label('Código del tipo')->required()->maxLength(40)->disabled($editing)->dehydrated(),
                TextInput::make('name')->label('Nombre del tipo')->required()->maxLength(120),
                Select::make('family')->label('Circuito financiero')->options(['BCP' => 'Agente BCP', 'TIGO_MONEY' => 'Tigo Money'])->default('BCP')->required()->disabled($editing)->dehydrated()->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $set('components', app(CashBoxTypeCatalog::class)->components($state ?? 'BCP'));
                    }),
                Toggle::make('is_active')->label('Disponible para nuevas cajas')->default(true),
                Hidden::make('expected_revision')->default(0),
            ]),
            Section::make('Saldos predeterminados')->description('Cada fila es un componente distinto, aunque comparta categoría. Se copiarán al crear una caja. Los importes se declaran en esa caja.')
                ->columnSpanFull()->schema([Repeater::make('components')->label('Componentes de la plantilla')->required()->minItems(1)->maxItems(50)
                ->default(fn (): array => app(CashBoxTypeCatalog::class)->components('BCP'))->columns(['default' => 1, 'md' => 2])->collapsible()
                ->itemLabel(fn (array $state): string => $state['name'] ?? 'Nuevo saldo')->addActionLabel('Añadir componente')->schema([
                    TextInput::make('code')->label('Código estable')->required()->maxLength(20)->helperText('Minúsculas y guion bajo; identifica este saldo.'),
                    TextInput::make('name')->label('Nombre sugerido')->required()->maxLength(100),
                    Select::make('category')->label('Tipo de saldo')->options(CashBoxAccountSettings::categories())->required(),
                    TextInput::make('reference')->label('Referencia predeterminada (opcional)')->maxLength(120),
                    Toggle::make('required')->label('Componente básico')->default(false)->helperText('Conserva Efectivo y el origen BCP/Yape como básicos.'),
                ])]),
            Section::make('Confirmación administrativa')->description('Autorizado por tu sesión; se conserva el motivo y el historial.')->columnSpanFull()->schema([Textarea::make('reason')->label('Motivo')->required()->minLength(3)->maxLength(2000), Hidden::make('request_key')->default(fn (): string => (string) Str::uuid())]),
        ];
    }
}
