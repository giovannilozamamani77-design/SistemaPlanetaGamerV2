<?php

namespace App\Filament\Widgets\Comisiones;

use App\Domain\Comisiones\Actions\VoidCommissionPaymentAction;
use App\Domain\Comisiones\Queries\AdminCommissionsQuery;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class CommissionPaymentsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    #[Locked]
    public int $userId = 0;

    public function mount(?int $userId = null): void
    {
        $this->userId = $userId ?? auth()->id();
        app(CommissionPaymentQuery::class)->authorize(User::findOrFail(auth()->id()), $this->userId);
    }

    public function table(Table $table): Table
    {
        return $table->heading('Historial de pagos de comisiones')->query(app(CommissionPaymentQuery::class)->history(User::findOrFail(auth()->id()), $this->userId))->queryStringIdentifier('commissionPayments')->paginated([5, 15, 25])->defaultPaginationPageOption(5)->defaultSort('commission_payments.id', 'desc')->emptyStateHeading('Sin pagos registrados')
            ->columns([
                TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
                TextColumn::make('amount_cents')->label('Importe')->color(fn ($record) => $record->reversal_id ? 'gray' : 'success')->weight('bold')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('registered_by_name')->label('Registrado por')->wrap(),
                TextColumn::make('status')->label('Estado')->state(fn ($record) => $record->reversal_id ? 'Anulado' : 'Registrado')->badge()->color(fn ($record) => $record->reversal_id ? 'danger' : 'success')->description(fn ($record) => $record->reversal_id ? 'Motivo: '.$record->reversal_reason : null)->wrap(),
                TextColumn::make('reversed_at')->label('Anulado el')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reversed_by_name')->label('Anulado por')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reference')->label('Referencia')->placeholder('Sin referencia'),
                TextColumn::make('note')->label('Observación')->placeholder('Sin observación')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])->recordActions([
                Action::make('voidPayment')->label('Anular')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn ($record) => app(AdminCommissionsQuery::class)->canView(auth()->user()) && ! $record->reversal_id)
                    ->modalHeading('Anular pago registrado por error')->modalDescription('El pago original queda en el historial como Anulado y su importe vuelve al pendiente. No se mueve dinero de las cajas.')
                    ->modalWidth(Width::TwoExtraLarge)->modalSubmitActionLabel('Anular pago')
                    ->fillForm(fn ($record) => ['payment_label' => 'Bs '.Bolivianos::formatCompact($record->amount_cents), 'request_key' => (string) Str::uuid(), 'confirmed' => false])
                    ->schema([
                        TextInput::make('payment_label')->label('Importe que vuelve al pendiente')->readOnly()->dehydrated(false),
                        Textarea::make('reason')->label('Motivo de la anulación')->required()->maxLength(300)->rows(2),
                        Hidden::make('request_key'),
                        Toggle::make('confirmed')->label('Confirmo que este registro de pago es incorrecto')->required()->accepted(),
                    ])->action(function ($record, array $data): void {
                        $data['payment_id'] = $record->id;
                        try {
                            app(VoidCommissionPaymentAction::class)->execute(auth()->user(), $data);
                        } catch (ValidationException $e) {
                            $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
                            $errors = [];
                            foreach ($e->errors() as $field => $messages) {
                                $errors[$path.'.'.$field] = $messages;
                            }
                            throw ValidationException::withMessages($errors);
                        } catch (QueryException $e) {
                            report($e);
                            $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
                            throw ValidationException::withMessages([$path.'.reason' => 'No se pudo registrar la anulación. Revisa el historial y reintenta desde esta ventana.']);
                        }
                        $this->flushCachedTableRecords();
                        $this->dispatch('commission-payments-updated');
                        Notification::make()->title('Pago anulado')->body('El importe volvió al pendiente y el historial se conservó.')->success()->send();
                    }),
            ]);
    }
}
