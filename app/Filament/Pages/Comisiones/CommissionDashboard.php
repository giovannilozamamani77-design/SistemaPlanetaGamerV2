<?php

namespace App\Filament\Pages\Comisiones;

use App\Domain\Comisiones\Actions\RegisterCommissionPaymentAction;
use App\Domain\Comisiones\Queries\AdminCommissionsQuery;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Filament\Widgets\Comisiones\AdminCommissionHistoryWidget;
use App\Filament\Widgets\Comisiones\CommissionPaymentsWidget;
use App\Models\User;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class CommissionDashboard extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'comisiones.dashboard';

    protected static ?string $title = 'Comisiones por cajera';

    protected static string|\UnitEnum|null $navigationGroup = 'Comisiones';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 0;

    public static function canAccess(): bool
    {
        return auth()->check() && app(AdminCommissionsQuery::class)->canView(auth()->user());
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        app(AdminCommissionsQuery::class)->authorize(User::findOrFail(auth()->id()));
    }

    public function overview(): array
    {
        return app(AdminCommissionsQuery::class)->overview(User::findOrFail(auth()->id()));
    }

    #[On('commission-payments-updated')]
    public function refreshCommissionBalances(): void
    {
        app(AdminCommissionsQuery::class)->authorize(auth()->user());
        $this->flushCachedTableRecords();
    }

    public function table(Table $table): Table
    {
        return $table->heading('Acumulado por cajera')->query(app(AdminCommissionsQuery::class)->cashiers(User::findOrFail(auth()->id())))
            ->queryStringIdentifier('commissionCashiers')->paginated([5, 15, 25])->defaultPaginationPageOption(5)->defaultSort('total_cents', 'desc')->emptyStateHeading('Sin cajeras registradas')
            ->columns([
                TextColumn::make('name')->label('Cajera / responsable')->searchable()->wrap(),
                TextColumn::make('accessory_cents')->label('Accesorios')->color('warning')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('phone_cents')->label('Teléfonos')->color('info')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('total_cents')->label('Ganado')->weight('bold')->color('success')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('paid_cents')->label('Pagado')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('pending_cents')->label('Pendiente')->weight('bold')->color('warning')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
                TextColumn::make('is_active')->label('Cuenta')->badge()->formatStateUsing(fn (bool $state) => $state ? 'Activa' : 'Desactivada')->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])->recordActions([
                Action::make('pay')->label('Registrar pago')->icon('heroicon-o-banknotes')->color('success')->disabled(fn (User $record) => (int) $record->pending_cents <= 0)
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
                    ])->action(function (User $record, array $data): void {
                        $data['user_id'] = $record->id;
                        try {
                            $payment = app(RegisterCommissionPaymentAction::class)->execute(auth()->user(), $data);
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
                            throw ValidationException::withMessages([$path.'.amount' => 'No se pudo completar el registro. Revisa el historial y reintenta desde esta ventana.']);
                        }
                        $this->flushCachedTableRecords();
                        if ($payment->reversal()->exists()) {
                            Notification::make()->title('Este pago ya existe y está anulado')->body('Revisa el historial. El pago no se ha vuelto a registrar.')->warning()->send();
                        } else {
                            Notification::make()->title('Pago registrado · Bs '.Bolivianos::formatCompact($payment->amount_cents))->success()->send();
                        }
                    }),
                Action::make('payments')->label('Ver pagos')->icon('heroicon-o-receipt-refund')->modalHeading(fn (User $record) => 'Pagos de '.$record->name)->modalWidth(Width::FiveExtraLarge)->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->schema(fn (User $record) => [Livewire::make(CommissionPaymentsWidget::class, ['userId' => $record->id])->key('commission-payments-'.$record->id)]),
                Action::make('history')->label('Ver ventas')->icon('heroicon-o-clock')->modalHeading(fn (User $record) => 'Comisiones de '.$record->name)->modalWidth(Width::SevenExtraLarge)->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->schema(fn (User $record) => [Livewire::make(AdminCommissionHistoryWidget::class, ['userId' => $record->id])->key('commission-history-'.$record->id)]),
            ]);
    }
}
