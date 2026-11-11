<?php

namespace App\Filament\Support\Comisiones;

use App\Domain\Comisiones\Actions\RegisterCommissionPaymentAction;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Models\User;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommissionPaymentActions
{
    public static function pay(): Action
    {
        return Action::make('pay')->label('Registrar pago')->icon('heroicon-o-banknotes')->color('success')->disabled(fn (User $record) => (int) $record->pending_cents <= 0)
            ->modalHeading(fn (User $record) => 'Pagar comisiones · '.$record->name)->modalDescription('Administración entrega este dinero por fuera de las cajas del sistema. Este registro reduce el pendiente y conserva el historial.')->modalWidth(Width::TwoExtraLarge)->modalSubmitActionLabel('Confirmar pago')
            ->fillForm(function (User $record): array {
                $balance = app(CommissionPaymentQuery::class)->balance(auth()->user(), $record->id);

                return ['amount' => number_format($balance['pending_cents'] / 100, 2, '.', ''), 'pending_label' => 'Bs '.Bolivianos::formatCompact($balance['pending_cents']), 'expected_earned_cents' => $balance['earned_cents'], 'expected_paid_cents' => $balance['paid_cents'], 'expected_reversal_count' => app(CommissionPaymentQuery::class)->reversalCount(auth()->user(), $record->id), 'request_key' => (string) Str::uuid(), 'delivered' => false];
            })->schema([
                TextInput::make('pending_label')->label('Pendiente actual')->readOnly()->dehydrated(false),
                TextInput::make('amount')->label('Importe a pagar')->prefix('Bs')->inputMode('decimal')->required()->helperText('Deja el total para cancelar todo o escribe una cantidad menor para un pago parcial.'),
                TextInput::make('reference')->label('Referencia (opcional)')->maxLength(100),
                Textarea::make('note')->label('Observación (opcional)')->rows(2)->maxLength(300),
                Hidden::make('expected_earned_cents'), Hidden::make('expected_paid_cents'), Hidden::make('expected_reversal_count'), Hidden::make('request_key'),
                Toggle::make('delivered')->label('Confirmo que Administración entregó este importe')->required()->accepted(),
            ])->action(function (User $record, array $data, $livewire): void {
                $data['user_id'] = $record->id;
                try {
                    $payment = app(RegisterCommissionPaymentAction::class)->execute(auth()->user(), $data);
                } catch (ValidationException $e) {
                    $path = $livewire->getSchema($livewire->getMountedActionSchemaName())?->getStatePath() ?? 'mountedActions.0.data';
                    $errors = [];
                    foreach ($e->errors() as $field => $messages) {
                        $errors[$path.'.'.$field] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                } catch (QueryException $e) {
                    report($e);
                    $path = $livewire->getSchema($livewire->getMountedActionSchemaName())?->getStatePath() ?? 'mountedActions.0.data';
                    throw ValidationException::withMessages([$path.'.amount' => 'No se pudo completar el registro. Revisa el historial y reintenta desde esta ventana.']);
                }
                $livewire->flushCachedTableRecords();
                if ($payment->reversal()->exists()) {
                    Notification::make()->title('Este pago ya existe y está anulado')->body('Revisa el historial. El pago no se ha vuelto a registrar.')->warning()->send();
                } else {
                    Notification::make()->title('Pago registrado · Bs '.Bolivianos::formatCompact($payment->amount_cents))->success()->send();
                }
            });
    }
}
