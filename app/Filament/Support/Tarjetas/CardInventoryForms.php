<?php

namespace App\Filament\Support\Tarjetas;

use App\Domain\Tarjetas\Calculators\CardInitialCountPreview;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Queries\CardStoreInventoryQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CardInventoryForms
{
    public static function modal(Action $action): Action
    {
        return $action->modal()->modalWidth('3xl')->stickyModalHeader()->stickyModalFooter()->modalCancelActionLabel('Cancelar')->modalSubmitActionLabel(fn (Action $action): string => (string) $action->getLabel());
    }

    public static function money(string $name, string $label, bool $required = true): TextInput
    {
        return TextInput::make($name)->label($label)->prefix('Bs')->inputMode('decimal')->required($required)->maxLength(12);
    }

    public static function key(): Hidden
    {
        return Hidden::make('request_key')->default(fn (): string => (string) Str::uuid());
    }

    public static function product(bool $editing = false): array
    {
        return [Section::make('Empresa y corte')->columns(2)->schema([
            Select::make('company')->label('Empresa')->options(CardInventoryInput::COMPANIES)->required()->disabled($editing)->dehydrated(),
            self::money('face_value', 'Valor del corte')->disabled($editing)->dehydrated(),
        ]), Section::make('Tres precios por tarjeta')->description('Se precargan al registrar operaciones. Cambiarlos aquí no modifica el historial.')->columns(3)->schema([
            self::money('purchase_price', 'Compra'), self::money('normal_price', 'Venta normal'), self::money('wholesale_price', 'Venta mayorista'),
        ]), Toggle::make('wholesale_enabled')->label('Habilitar este corte para venta mayorista')->default(false)->visible(fn (): bool => Gate::allows('update', CardProduct::class))->dehydrated(fn (): bool => Gate::allows('update', CardProduct::class)), Toggle::make('is_active')->label('Disponible para nuevos movimientos')->default(true), Hidden::make('expected_revision')->default(0), self::key()];
    }

    public static function initialize(): array
    {
        $products = app(CardStoreInventoryQuery::class)->products(auth()->user())->where('is_active', true)->orderBy('company')->orderBy('face_value_cents')->get();
        $sections = [];
        foreach (CardInventoryInput::COMPANIES as $company => $label) {
            $fields = [];
            $ids = [];
            foreach ($products->where('company', $company) as $product) {
                $id = $product->id;
                $ids[] = $id;
                $fields[] = Hidden::make("stocks.{$id}.product_id")->default($id);
                $fields[] = TextInput::make("stocks.{$id}.quantity")->label($product->cutLabel())->suffix('unid.')->default(0)->integer()->minValue(0)->maxValue(1000000)->required()->live(debounce: 350);
            }
            $fields[] = Text::make(function (Get $get) use ($ids): string {
                $rows = array_intersect_key((array) $get('stocks'), array_flip($ids));
                $total = app(CardInitialCountPreview::class)->units($rows);

                return $total === null ? 'Revisa las cantidades.' : 'Total de la empresa: '.$total.' tarjetas';
            })->color('primary')->columnSpanFull();
            $sections[] = Section::make($label)->compact()->columns(2)->schema($fields);
        }

        return [Grid::make(['default' => 1, 'lg' => 3])->schema($sections),
            Section::make('Resumen del conteo inicial')->compact()->columns(2)->schema([
                self::money('cash_amount', 'Efectivo real de Tarjetas')->helperText('Escribe 0 si no hay efectivo. No se descuenta el valor de las tarjetas.')->live(debounce: 350),
                Text::make(function (Get $get): string {
                    $total = app(CardInitialCountPreview::class)->units((array) $get('stocks'));
                    try {
                        $cash = filled($get('cash_amount')) ? 'Bs '.Bolivianos::formatCompact(CardInventoryInput::money($get('cash_amount'), 'cash_amount', true)) : 'pendiente de ingresar';
                    } catch (ValidationException) {
                        $cash = 'revisa el importe';
                    }

                    return 'Total contado: '.($total === null ? 'revisa las cantidades' : $total.' tarjetas').' · Efectivo: '.$cash;
                })->color('primary'),
                Text::make('Comprueba todas las cantidades antes de confirmar. El costo histórico permanece desconocido; no usamos el precio del catálogo como costo inicial. Esta carga se registra una sola vez.')->columnSpanFull(),
            ]), self::key()];
    }

    public static function productValues(CardProduct $product): array
    {
        return ['company' => $product->company, 'face_value' => Bolivianos::formatCompact($product->face_value_cents), 'purchase_price' => Bolivianos::formatCompact($product->purchase_price_cents), 'normal_price' => Bolivianos::formatCompact($product->normal_price_cents), 'wholesale_price' => Bolivianos::formatCompact($product->wholesale_price_cents), 'is_active' => $product->is_active, 'wholesale_enabled' => $product->wholesale_enabled, 'expected_revision' => $product->revision, 'request_key' => (string) Str::uuid()];
    }
}
