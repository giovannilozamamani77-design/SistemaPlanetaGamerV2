<?php

namespace App\Filament\Support\Accesorios;

use App\Domain\Accesorios\Actions\RecordAccessoryCashDepositAction;
use App\Domain\Accesorios\Queries\AccessoryCashDepositQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class AccessoryCashDepositForms
{
    public static function action(): Action
    {
        return Action::make('depositCash')->label('Depositar efectivo a Administración')->icon('heroicon-o-arrow-up-on-square')->color('primary')->modalHeading('Depositar efectivo a Administración')->modalWidth('3xl')->modalSubmitActionLabel('Registrar depósito realizado')
            ->fillForm(function (): array {
                $q = app(AccessoryCashDepositQuery::class);
                $options = $q->accounts(auth()->user());
                $id = count($options) === 1 ? array_key_first($options) : null;
                $quote = $q->quote(auth()->user(), $id);

                return ['account_id' => $id, 'account_revision' => $quote['destination']['revision'] ?? null, 'expected_revision' => $quote['expected_revision'], 'request_key' => (string) Str::uuid(), 'confirmed' => false];
            })->schema([
                Hidden::make('request_key'), Hidden::make('expected_revision'), Hidden::make('account_revision'),
                Select::make('account_id')->label('Cuenta de Administración')->options(fn () => app(AccessoryCashDepositQuery::class)->accounts(auth()->user()))->required()->live()->afterStateUpdated(function ($state, Set $set): void {
                    $quote = app(AccessoryCashDepositQuery::class)->quote(auth()->user(), $state ? (int) $state : null);
                    $set('account_revision', $quote['destination']['revision'] ?? null);
                }),
                View::make('accesorios.deposit-preview')->viewData(fn (Get $get) => app(AccessoryCashDepositQuery::class)->quote(auth()->user(), $get('account_id') ? (int) $get('account_id') : null)),
                TextInput::make('amount')->label('Importe depositado · Bs')->prefix('Bs')->inputMode('decimal')->required(),
                TextInput::make('operation_reference')->label('Número de operación (opcional)')->maxLength(200),
                Toggle::make('confirmed')->label('Ya realicé este depósito a Administración')->accepted()->required(),
            ])->action(function (array $data, Component $livewire): void {
                self::run($livewire, function () use ($data): void {
                    $d = app(RecordAccessoryCashDepositAction::class)->execute(auth()->user(), $data);
                    Notification::make()->title('Depósito #'.$d->id.' registrado')->body('Efectivo disponible: Bs '.Bolivianos::formatCompact(app(AccessoryCashDepositQuery::class)->quote(auth()->user())['cash_cents']))->success()->send();
                });
            });
    }

    public static function history(): Action
    {
        return Action::make('cashDepositHistory')->label('Historial de depósitos')->icon('heroicon-o-clock')->color('gray')->modalHeading('Depósitos de efectivo a Administración')->modalWidth('7xl')->modalSubmitAction(false)->modalContent(fn () => view('accesorios.deposit-history-modal', ['historyKey' => (string) Str::uuid()]));
    }

    public static function run(Component $livewire, \Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $e) {
            $path = $livewire->getSchema($livewire->getMountedActionSchemaName())?->getStatePath() ?? 'mountedActions.0.data';
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors[$path.'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }
}
