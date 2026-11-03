<?php

namespace App\Filament\Support\Tarjetas;

use App\Domain\Tarjetas\Actions\RecordCardCashDepositAction;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Queries\CardDepositQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CardDepositForms
{
    public static function action(): Action
    {
        return CardInventoryForms::modal(Action::make('depositCash'))->label('Depositar efectivo a Administración')->icon('heroicon-o-arrow-up-on-square')->modalHeading('Depositar efectivo a Administración')->modalWidth('3xl')->modalSubmitActionLabel('Registrar depósito realizado')
            ->visible(fn () => Gate::allows('deposit', CardProduct::class))
            ->fillForm(function (): array {
                $options = app(CardDepositQuery::class)->options(auth()->user());
                $id = count($options) === 1 ? array_key_first($options) : null;
                $q = app(CardDepositQuery::class)->quote(auth()->user(), $id);

                return ['account_id' => $id, 'account_revision' => $q['destination']['revision'] ?? null, 'request_key' => (string) Str::uuid()];
            })
            ->schema([
                Select::make('account_id')->label('Cuenta de Administración')->options(fn () => app(CardDepositQuery::class)->options(auth()->user()))->required()->live()->afterStateUpdated(function ($state, Set $set): void {
                    $q = app(CardDepositQuery::class)->quote(auth()->user(), $state ? (int) $state : null);
                    $set('account_revision', $q['destination']['revision'] ?? null);
                }),
                Hidden::make('account_revision'),
                View::make('tarjetas.deposit-preview')->viewData(fn (Get $get) => app(CardDepositQuery::class)->quote(auth()->user(), $get('account_id') ? (int) $get('account_id') : null)),
                CardInventoryForms::money('amount', 'Importe depositado'),
                TextInput::make('operation_reference')->label('Número de operación o transacción (opcional)')->maxLength(200),
                CardInventoryForms::key(),
            ])->action(function (array $data): void {
                try {
                    $m = app(RecordCardCashDepositAction::class)->execute(auth()->user(), $data);
                } catch (ValidationException $e) {
                    $errors = $e->errors();
                    if (isset($errors['request_key'])) {
                        $errors['amount'] = $errors['request_key'];
                        unset($errors['request_key']);
                    }throw ValidationException::withMessages($errors);
                }
                Notification::make()->title('Depósito #'.$m->id.' registrado; efectivo actualizado')->success()->send();
            });
    }
}
