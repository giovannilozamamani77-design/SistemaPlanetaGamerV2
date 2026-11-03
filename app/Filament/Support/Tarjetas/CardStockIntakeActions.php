<?php

namespace App\Filament\Support\Tarjetas;

use App\Domain\Tarjetas\Actions\RecordCardStockBatchAction;
use App\Domain\Tarjetas\Calculators\CardStockBatchCalculator;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Queries\CardStockIntakeQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CardStockIntakeActions
{
    public static function purchase(?\Closure $after = null): Action
    {
        return self::make(false, $after);
    }

    public static function delivery(?\Closure $after = null): Action
    {
        return self::make(true, $after);
    }

    private static function make(bool $delivery, ?\Closure $after): Action
    {
        return CardInventoryForms::modal(Action::make($delivery ? 'receiveEntel' : 'purchase'))->label($delivery ? 'Recibir tarjetas de Administración' : 'Registrar compra')->icon($delivery ? 'heroicon-o-inbox-arrow-down' : 'heroicon-o-shopping-cart')->color($delivery ? 'gray' : 'primary')->modalWidth('7xl')->modalSubmitActionLabel($delivery ? 'Confirmar recepción' : 'Registrar compra')
            ->visible(fn () => Gate::allows($delivery ? 'receive' : 'purchase', CardProduct::class))
            ->modalDescription($delivery ? 'Selecciona la empresa y anota las tarjetas entregadas por Administración. Solo aumenta el stock; no se descuenta efectivo.' : 'Selecciona una empresa y completa sus cortes. Compra pagada descuenta efectivo; las entregas sin pago se registran en Recibir tarjetas de Administración.')
            ->fillForm(fn () => ['company' => null, 'lines' => [], 'price_review' => false, 'price_changes' => [], 'request_key' => (string) Str::uuid()])
            ->modalFooterActions([])
            ->modalContentFooter(fn (Action $action) => $delivery ? view('tarjetas.delivery-footer', self::deliverySummary($action->getRawData())) : view('tarjetas.purchase-footer', self::purchaseSummary($action->getRawData())))
            ->schema(fn () => Gate::allows($delivery ? 'receive' : 'purchase', CardProduct::class) ? self::schema($delivery) : [])
            ->action(function (array $data, Action $action) use ($delivery, $after): void {
                try {
                    if (! $delivery) {
                        $changes = app(CardStockIntakeQuery::class)->priceChanges(auth()->user(), $data);
                        if ($changes && ! in_array($data['price_decision'] ?? null, ['KEEP', 'UPDATE'], true)) {
                            $index = $action->getNestingIndex();
                            $action->getLivewire()->mountedActions[$index]['data']['price_review'] = true;
                            $action->getLivewire()->mountedActions[$index]['data']['price_changes'] = $changes;
                            $action->halt();
                        }
                        $data['remember_prices'] = ($data['price_decision'] ?? null) === 'UPDATE';
                        $data['price_revisions'] = collect($data['price_changes'] ?? [])->pluck('revision', 'product_id')->all();
                    }
                    app(RecordCardStockBatchAction::class)->{$delivery ? 'delivery' : 'purchase'}(auth()->user(), $data);
                } catch (ValidationException $e) {
                    $errors = $e->errors();
                    if (! $delivery && ($data['price_review'] ?? false)) {
                        $errors = ['price_decision' => array_merge(...array_values($errors))];
                    } elseif (isset($errors['request_key'])) {
                        $errors['reference'] = $errors['request_key'];
                        unset($errors['request_key']);
                    }throw ValidationException::withMessages($errors);
                }
                if ($after) {
                    $after();
                }Notification::make()->title($delivery ? 'Tarjetas de Administración recibidas sin descontar efectivo' : 'Compra registrada; stock y efectivo actualizados')->success()->send();
            });
    }

    private static function priceReview(array $changes): array
    {
        return array_map(fn ($change) => ['label' => $change['label'], 'old' => Bolivianos::formatCompact($change['old_cents']), 'new' => Bolivianos::formatCompact($change['new_cents'])], $changes);
    }

    private static function cards(array $lines): array
    {
        $cards = [];
        foreach ($lines as $key => $line) {
            $preview = app(CardStockBatchCalculator::class)->preview([$line], false, null);
            $cards[$key] = ['label' => $line['label'] ?? 'Corte', 'subtotal' => $preview ? Bolivianos::formatCompact($preview['total_cents']) : '—'];
        }

        return $cards;
    }

    private static function purchaseSummary(array $data): array
    {
        $cash = app(CardStockIntakeQuery::class)->cash(auth()->user());
        $preview = app(CardStockBatchCalculator::class)->preview((array) ($data['lines'] ?? []), false, $cash);

        return ['cash' => $cash === null ? 'Sin conteo inicial' : 'Bs '.Bolivianos::formatCompact($cash), 'total' => $preview ? 'Bs '.Bolivianos::formatCompact($preview['total_cents']) : 'Bs 0', 'units' => $preview['total_quantity'] ?? 0, 'insufficient' => $preview !== null && ! $preview['enough_cash'], 'ready' => filled($data['company'] ?? null) && $preview !== null && $preview['enough_cash']];
    }

    private static function deliverySummary(array $data): array
    {
        $preview = app(CardStockBatchCalculator::class)->preview((array) ($data['lines'] ?? []), true, null);

        return ['units' => $preview['total_quantity'] ?? 0, 'ready' => filled($data['company'] ?? null) && $preview !== null];
    }

    private static function schema(bool $delivery): array
    {
        return [
            View::make('tarjetas.purchase-cash')->visible(! $delivery)->viewData(fn () => self::purchaseSummary([])),
            ToggleButtons::make('company')->visible(fn (Get $get) => ! filled($get('company')))->dehydratedWhenHidden()->label('Selecciona la empresa')->options(collect(['TIGO' => 'Tigo', 'VIVA' => 'Viva', 'ENTEL' => 'Entel'])->map(fn ($name, $company) => new HtmlString(view('tarjetas.prepaid-company', compact('name', 'company'))->render()))->all())->colors(['TIGO' => 'info', 'VIVA' => 'success', 'ENTEL' => 'primary'])->columns(3)->required()->live()->extraAttributes(['class' => 'pg-purchase-companies'])->afterStateUpdated(fn ($state, Set $set) => $set('lines', app(CardStockIntakeQuery::class)->lines(auth()->user(), $state, $delivery))),
            Actions::make([
                Action::make('changePurchaseCompany')->label('← Cambiar empresa')->color('gray')->link()->action(function (Set $set): void {
                    $set('company', null);
                    $set('lines', []);
                    $set('price_review', false);
                    $set('price_changes', []);
                    $set('price_decision', null);
                }),
                Action::make('editPurchase')->label('← Volver a la compra')->color('gray')->link()->visible(fn (Get $get) => ! $delivery && (bool) $get('price_review'))->action(function (Set $set): void {
                    $set('price_review', false);
                    $set('price_changes', []);
                    $set('price_decision', null);
                }),
            ])->key('purchaseNavigation')->visible(fn (Get $get) => filled($get('company'))),
            View::make('tarjetas.purchase-price-review')->visible(fn (Get $get) => ! $delivery && (bool) $get('price_review'))->viewData(fn (Get $get) => ['changes' => self::priceReview((array) $get('price_changes'))]),
            Radio::make('price_decision')->label('¿Usar estos precios de compra en las próximas compras?')->options(['UPDATE' => 'Sí, actualizar el precio de referencia', 'KEEP' => 'No, solo para esta compra'])->required()->visible(fn (Get $get) => ! $delivery && (bool) $get('price_review')),
            Hidden::make('price_review')->default(false), Hidden::make('price_changes')->default([]),
            ViewField::make('lines')->label($delivery ? 'Cortes recibidos' : 'Cortes a comprar')->view('tarjetas.purchase-cuts')->visible(fn (Get $get) => filled($get('company')) && ! $get('price_review'))->dehydratedWhenHidden()->viewData(fn (Get $get) => ['cards' => self::cards((array) $get('lines')), 'company' => $get('company'), 'delivery' => $delivery]),
            TextInput::make('reference')->label($delivery ? 'Entregado por / referencia (opcional)' : 'Proveedor o referencia (opcional)')->maxLength(200)->visible(fn (Get $get) => ! $get('price_review'))->dehydratedWhenHidden(), CardInventoryForms::key(),
        ];
    }
}
