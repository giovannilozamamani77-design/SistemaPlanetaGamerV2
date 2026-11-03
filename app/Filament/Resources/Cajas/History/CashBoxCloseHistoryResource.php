<?php

namespace App\Filament\Resources\Cajas\History;

use App\Domain\Cajas\Actions\ApproveRegisteredCashBoxCloseAction;
use App\Domain\Cajas\Actions\LiquidateRegisteredCashBoxCloseAction;
use App\Domain\Cajas\Actions\RestoreRegisteredCashBoxCapitalAction;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Queries\ClosingHistoryQuery;
use App\Domain\Cajas\Queries\ClosingRestorationHistoryQuery;
use App\Domain\Liquidaciones\Queries\RegisteredClosingLiquidationQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CashBoxCloseHistoryResource extends Resource
{
    protected static ?string $model = CashBoxClose::class;

    protected static ?string $pluralModelLabel = 'Historial de cierres';

    protected static ?string $navigationLabel = 'Historial de cierres';

    protected static string|\UnitEnum|null $navigationGroup = 'Cajas';

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'historial-cierres';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('cajas.operar') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(ClosingHistoryQuery::class)->forUser(auth()->user());
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('box.name')->label('Caja'), TextColumn::make('user.name')->label('Cajera'),
            TextColumn::make('period')->label('Turno')->formatStateUsing(fn (string $state): string => $state === 'manana' ? 'Mañana' : 'Tarde'),
            TextColumn::make('created_at')->label('Cierre')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
            TextColumn::make('liquidated_cents')->label('Liquidado')->visible(fn (): bool => auth()->user()->hasRole('administrador') && auth()->user()->can('cajas.liberar'))->formatStateUsing(fn ($state): string => 'Bs '.Bolivianos::format((int) $state)),
            TextColumn::make('posterior')->label('Saldo posterior')->state(fn (CashBoxClose $record): string => 'Bs '.Bolivianos::format($record->realTotal())),
            TextColumn::make('close_status')->label('Estado')->state(fn (CashBoxClose $record): string => $record->cancelled_at ? 'Anulado' : ($record->released_at ? 'Finalizado' : 'Pendiente de revisión'))->badge(),
        ])->filters([
            SelectFilter::make('cash_box_id')->label('Caja')->relationship('box', 'name'),
            SelectFilter::make('user_id')->label('Cajera')->relationship('user', 'name')->searchable(),
            SelectFilter::make('period')->label('Turno')->options(['manana' => 'Mañana', 'tarde' => 'Tarde']),
            Filter::make('dates')->label('Fecha de cierre')->columns(2)->columnSpanFull()
                ->schema([DatePicker::make('from')->label('Desde')->displayFormat('d/m/Y'), DatePicker::make('until')->label('Hasta')->displayFormat('d/m/Y')])
                ->query(fn (Builder $query, array $data): Builder => app(ClosingHistoryQuery::class)->dates($query, $data))
                ->indicateUsing(fn (array $data): array => array_filter([
                    'from' => filled($data['from'] ?? null) ? 'Desde: '.$data['from'] : null,
                    'until' => filled($data['until'] ?? null) ? 'Hasta: '.$data['until'] : null,
                ])),
        ], layout: FiltersLayout::Dropdown)->filtersFormColumns(2)->filtersFormWidth('xl')
            ->paginated([5])->defaultPaginationPageOption(5)->defaultSort('id', 'desc')->recordUrl(null)
            ->recordActions([
                Action::make('detail')->label('Ver detalle')->icon('heroicon-o-eye')->iconButton()->modalHeading('Detalle del cierre')->modalWidth('4xl')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (CashBoxClose $record) => auth()->user()->hasRole('administrador') && auth()->user()->can('cajas.liberar')
                        ? view('cajas.closing-history-detail', ['record' => $record, 'restorations' => app(ClosingRestorationHistoryQuery::class)->execute(auth()->user(), $record->id)])
                        : view('cajas.closing-history-public-detail', ['record' => $record])),
                Action::make('restore')->label('Registrar reposición')->icon('heroicon-o-banknotes')->color('info')
                    ->visible(fn (CashBoxClose $record): bool => auth()->user()->hasRole('administrador') && auth()->user()->can('cajas.liberar') && $record->workflow_version === 3 && ! $record->cancelled_at && ! $record->released_at && ($record->operatingTotal() < $record->capital_cents || $record->difference_cents < 0))
                    ->modalHeading('Registrar reposición real del faltante')->modalDescription('Registra únicamente dinero que ya fue entregado. No modifica el conteo original ni cancela la incidencia de la cajera.')
                    ->schema([
                        Select::make('component')->label('Cuenta que recibió el dinero')->options(fn (CashBoxClose $record): array => array_combine(array_keys($record->real_balances), array_keys($record->real_balances)))->required(),
                        TextInput::make('amount')->label('Importe realmente entregado')->prefix('Bs')->required(),
                        Textarea::make('receipt')->label('Referencia o detalle de la entrega')->required()->minLength(3),
                        Toggle::make('occurred')->label('Confirmo que el dinero ya fue entregado')->accepted(),
                        Hidden::make('expected_revision')->default(fn (CashBoxClose $record): int => CashBoxSession::findOrFail($record->cash_box_session_id)->revision),
                        Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
                    ])->modalSubmitActionLabel('Registrar reposición real')->modalCancelActionLabel('Volver sin registrar')
                    ->action(fn (CashBoxClose $record, array $data) => app(RestoreRegisteredCashBoxCapitalAction::class)->execute(auth()->user(), $record->id, $data)),
                Action::make('settleRestored')->label('Liquidar reposición')->icon('heroicon-o-qr-code')->color('warning')
                    ->visible(fn (CashBoxClose $record): bool => auth()->user()->hasRole('administrador') && auth()->user()->can('cajas.liberar') && $record->workflow_version === 3 && ! $record->cancelled_at && ! $record->released_at && $record->requires_liquidation && ! $record->liquidation_completed)
                    ->modalHeading('Liquidación después de reposición')->modalWidth('4xl')
                    ->modalContent(fn (CashBoxClose $record) => view('liquidaciones.registered-closing-preview', ['quote' => app(RegisteredClosingLiquidationQuery::class)->execute(auth()->user(), $record->id)]))
                    ->fillForm(function (CashBoxClose $record): array {
                        $quote = app(RegisteredClosingLiquidationQuery::class)->execute(auth()->user(), $record->id);

                        return ['expected_revision' => $quote['revision'], 'account_id' => $quote['account']?->id, 'amount_cents' => $quote['calculation']['amount_cents'], 'request_key' => (string) Str::uuid()];
                    })
                    ->schema([Hidden::make('expected_revision'), Hidden::make('account_id'), Hidden::make('amount_cents'), Hidden::make('request_key'),
                        TextInput::make('operation_reference')->label('Referencia de la transferencia')->maxLength(120),
                        Toggle::make('transferred')->label('Confirmo la transferencia real, si hay importe positivo')])
                    ->modalSubmitActionLabel('Confirmar liquidación')->modalCancelActionLabel('Volver sin registrar')
                    ->action(fn (CashBoxClose $record, array $data) => app(LiquidateRegisteredCashBoxCloseAction::class)->execute(auth()->user(), $record->id, $data)),
                Action::make('approve')->label('Autorizar cierre')->icon('heroicon-o-shield-check')->color('success')
                    ->visible(fn (CashBoxClose $record): bool => auth()->user()->hasRole('administrador') && auth()->user()->can('cajas.liberar') && $record->workflow_version === 3 && ! $record->cancelled_at && ! $record->released_at)
                    ->modalHeading('Revisar cierre con faltante')->modalDescription('Confirma la continuidad de la caja. La incidencia y su responsabilidad se conservan para revisión independiente.')
                    ->schema([Textarea::make('reason')->label('Motivo de la confirmación (opcional)')->maxLength(2000)->helperText('Si lo dejas vacío, se guardará una constancia automática con quién autorizó y cuándo.'),
                        Toggle::make('exception')->label('Confirmación excepcional de mi propio cierre')
                            ->helperText('Solo para tu propio cierre si eres el único administrador activo. Si hay otro administrador, debe revisarlo él.')
                            ->visible(fn (CashBoxClose $record): bool => $record->user_id === auth()->id())
                            ->dehydrated(fn (CashBoxClose $record): bool => $record->user_id === auth()->id())
                            ->accepted(fn (CashBoxClose $record): bool => $record->user_id === auth()->id()),
                        Hidden::make('request_key')->default(fn (): string => (string) Str::uuid())])
                    ->modalSubmitActionLabel('Confirmar cierre')->modalCancelActionLabel('Volver sin registrar')
                    ->action(fn (CashBoxClose $record, array $data) => app(ApproveRegisteredCashBoxCloseAction::class)->execute(auth()->user(), $record->id, $data)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCashBoxCloses::route('/')];
    }
}
