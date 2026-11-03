<?php

namespace App\Filament\Pages\Tarjetas;

use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Queries\CardDepositQuery;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Tarjetas\CardDepositForms;
use App\Filament\Support\Tarjetas\CardInventoryForms;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class CardCashDeposits extends Page implements HasTable
{
    use HandlesPersonnelActionErrors, InteractsWithTable;

    protected string $view = 'tarjetas.cash-deposits';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Depósitos a Administración';

    protected static string|\UnitEnum|null $navigationGroup = 'Tarjetas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-on-square';

    protected static ?int $navigationSort = 4;

    protected ?array $historyOptions = null;

    protected function historyFilterOptions(): array
    {
        return $this->historyOptions ??= app(CardDepositQuery::class)->historyFilterOptions(auth()->user());
    }

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::allows('deposit', CardProduct::class) && Gate::allows('history', CardProduct::class);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('inventory')->label('Volver al inventario')->icon('heroicon-o-arrow-left')->color('gray')->visible(fn () => StoreInventory::canAccess())->url(StoreInventory::getUrl()), CardDepositForms::action()->after(fn () => $this->historyOptions = null), Action::make('accounts')->label('Configurar cuentas')->icon('heroicon-o-cog-6-tooth')->color('gray')->visible(fn () => CardDepositAccounts::canAccess())->url(CardDepositAccounts::getUrl())];
    }

    public function table(Table $table): Table
    {
        return $table->query(app(CardDepositQuery::class)->history(auth()->user()))->columns([
            TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->description(fn (CardInventoryMovement $record) => 'Depósito #'.$record->id),
            TextColumn::make('user.name')->label('Cajera'),
            TextColumn::make('destination_snapshot.name')->label('Cuenta')->description(fn (CardInventoryMovement $record) => ($record->destination_snapshot['bank'] ?? '').' · '.($record->destination_snapshot['number'] ?? ''))->wrap(),
            TextColumn::make('cash_delta_cents')->label('Monto')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact(abs($state)))->weight('bold'),
            TextColumn::make('reference')->label('N.º de operación')->placeholder('Sin número'),
        ])->filters([
            Filter::make('history')->label('Buscar depósitos')->schema([
                DatePicker::make('from')->label('Desde')->native(),
                DatePicker::make('until')->label('Hasta')->native()->afterOrEqual('from'),
                Select::make('user_id')->label('Cajera')->options(fn () => $this->historyFilterOptions()['users'])->placeholder('Todas')->searchable(),
                Select::make('account_id')->label('Cuenta')->options(fn () => $this->historyFilterOptions()['accounts'])->placeholder('Todas')->searchable(),
            ])->query(fn (Builder $query, array $data) => app(CardDepositQuery::class)->filterHistory($query, $data))
                ->indicateUsing(fn (array $data) => array_values(array_filter([
                    filled($data['from'] ?? null) ? 'Desde: '.$data['from'] : null,
                    filled($data['until'] ?? null) ? 'Hasta: '.$data['until'] : null,
                    $this->historyFilterOptions()['users'][$data['user_id'] ?? ''] ?? null,
                    $this->historyFilterOptions()['accounts'][$data['account_id'] ?? ''] ?? null,
                ]))),
        ])->filtersFormColumns(2)->filtersTriggerAction(fn (Action $action) => $action->label('Filtrar depósitos')->button()->size('sm'))->recordActions([CardInventoryForms::modal(Action::make('detail'))->label('Detalle')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->fillForm(fn (CardInventoryMovement $record) => [
            'bank' => $record->destination_snapshot['bank'] ?? null, 'number' => $record->destination_snapshot['number'] ?? null, 'holder' => $record->destination_snapshot['holder'] ?? null, 'reference' => $record->reference, 'before' => $record->cash_before_cents, 'after' => $record->cash_after_cents,
        ])->schema([TextEntry::make('bank')->state(fn (CardInventoryMovement $record) => $record->destination_snapshot['bank'] ?? null)->label('Banco'), TextEntry::make('number')->state(fn (CardInventoryMovement $record) => $record->destination_snapshot['number'] ?? null)->label('Cuenta'), TextEntry::make('holder')->state(fn (CardInventoryMovement $record) => $record->destination_snapshot['holder'] ?? null)->label('Titular'), TextEntry::make('reference')->state(fn (CardInventoryMovement $record) => $record->reference)->label('N.º de operación')->placeholder('Sin número'), TextEntry::make('before')->state(fn (CardInventoryMovement $record) => $record->cash_before_cents)->label('Efectivo antes')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact($state)), TextEntry::make('after')->state(fn (CardInventoryMovement $record) => $record->cash_after_cents)->label('Efectivo después')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact($state))])])->paginated([5, 10, 25, 50])->defaultPaginationPageOption(5)->defaultSort(fn ($query) => $query->orderBy('created_at', 'desc')->orderBy('id', 'desc'))->emptyStateHeading('Todavía no hay depósitos a Administración');
    }
}
