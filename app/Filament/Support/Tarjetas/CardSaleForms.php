<?php

namespace App\Filament\Support\Tarjetas;

use App\Domain\Tarjetas\Calculators\CardSaleCalculator;
use App\Support\Bolivianos;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class CardSaleForms
{
    public static function schema(): array
    {
        return [Section::make('Nueva venta')->compact()->schema([
            Select::make('mode')->label('Precio de venta')->options(['NORMAL' => 'Normal', 'WHOLESALE' => 'Mayorista'])->default('NORMAL')->required()->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set, $livewire): void {
                    $catalog = $livewire->saleCatalog();
                    foreach ((array) $get('lines') as $key => $line) {
                        $p = $catalog[$line['product_id'] ?? 0] ?? null;
                        $set('lines.'.$key.'.unit_price', $p ? Bolivianos::formatCompact($p[$state === 'WHOLESALE' ? 'wholesale_price_cents' : 'normal_price_cents']) : null);
                    }
                }),
            Repeater::make('lines')->label('Tarjetas y Cabina Viva')->defaultItems(1)->minItems(1)->maxItems(100)->reorderable(false)->addActionLabel('Agregar otro corte')->columns(4)->schema([
                Select::make('product_id')->label('Producto')->columnSpan(2)->searchable()->required()->live()
                    ->options(function (Get $get, $livewire): array {
                        return collect($livewire->saleCatalog())->filter(fn ($p) => $get('../../mode') !== 'WHOLESALE' || $p['wholesale_enabled'])->mapWithKeys(fn ($p, $id) => [$id => $p['label'].' · '.$p['quantity'].' disponibles'])->all();
                    })
                    ->afterStateUpdated(function ($state, Get $get, Set $set, $livewire): void {
                        $p = $livewire->saleCatalog()[$state] ?? null;
                        $set('unit_price', $p ? Bolivianos::formatCompact($p[$get('../../mode') === 'WHOLESALE' ? 'wholesale_price_cents' : 'normal_price_cents']) : null);
                    }),
                TextInput::make('quantity')->label('Cantidad')->default(1)->integer()->minValue(1)->maxValue(1000000)->required()->live(debounce: 350),
                CardInventoryForms::money('unit_price', 'Precio por unidad')->helperText('Aplicado solo a esta venta.')->live(debounce: 350),
            ]),
            Text::make(function (Get $get): string {
                $total = app(CardSaleCalculator::class)->preview((array) $get('lines'));

                return $total ? 'Total a cobrar: Bs '.Bolivianos::formatCompact($total['total_cents']).' · '.$total['total_quantity'].' unidades' : 'Elige los productos para calcular el total a cobrar.';
            })->color('primary'),
            Text::make('Cobro únicamente en efectivo. Confirma después de cobrar. Cambiar a mayorista vuelve a precargar los precios; revisa cualquier precio personalizado antes de registrar.'),
            Hidden::make('payment_method')->default('CASH'), CardInventoryForms::key(),
        ])];
    }
}
