<?php

namespace App\Filament\Support\Tarjetas;

use App\Domain\Tarjetas\Actions\RecordCardCashFundingAction;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Queries\CardCashFundingQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CardCashFundingForms
{
    public static function action(): Action
    {
        return CardInventoryForms::modal(Action::make('receiveCash'))->label('Ingresar efectivo')->modalHeading('Efectivo entregado por Administración')->modalDescription('Registra solo el dinero realmente entregado a tienda para compras. No es una venta ni una ganancia.')->icon('heroicon-o-banknotes')->color('success')->modalWidth('lg')->modalSubmitActionLabel('Registrar efectivo entregado')->visible(fn () => Gate::allows('cashFunding', CardProduct::class))->schema([
            CardInventoryForms::money('amount', 'Efectivo entregado')->live(debounce: 350),
            View::make('tarjetas.cash-funding-preview')->viewData(fn (Get $get) => app(CardCashFundingQuery::class)->quote(auth()->user(), $get('amount'))),
            TextInput::make('reference')->label('Referencia de entrega (opcional)')->maxLength(200), CardInventoryForms::key(),
        ])->action(function (array $data): void {
            try {
                $m = app(RecordCardCashFundingAction::class)->execute(auth()->user(), $data);
            } catch (ValidationException $e) {
                $errors = $e->errors();
                if (isset($errors['request_key'])) {
                    $errors['amount'] = $errors['request_key'];
                    unset($errors['request_key']);
                }throw ValidationException::withMessages($errors);
            }
            Notification::make()->title('Entrada #'.$m->id.' registrada; efectivo disponible actualizado')->success()->send();
        });
    }
}
