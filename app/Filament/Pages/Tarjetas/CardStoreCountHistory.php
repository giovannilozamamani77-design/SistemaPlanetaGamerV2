<?php

namespace App\Filament\Pages\Tarjetas;

use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Tarjetas\CardInventoryForms;
use App\Models\User;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

class CardStoreCountHistory extends Page implements HasTable
{
    use HandlesPersonnelActionErrors,InteractsWithTable;

    protected string $view = 'tarjetas.store-history-selector';

    #[Locked]
    public string $historyKind = 'CLOSE';

    protected ?array $cashiers = null;

    protected function historyCashiers(): array
    {
        return $this->cashiers ??= app(CardStoreSessionQuery::class)->historyCashiers(auth()->user());
    }

    public function selectHistory(string $kind): void
    {
        app(CardStoreSessionQuery::class)->history(auth()->user(), $kind);
        $this->historyKind = $kind;
        $this->resetTable();
    }

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationLabel = 'Historiales';

    protected static ?string $title = 'Historiales de Tarjetas';

    protected static string|\UnitEnum|null $navigationGroup = 'Tarjetas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::allows('history', CardProduct::class);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('inventory')->label('Volver al inventario')->icon('heroicon-o-arrow-left')->color('gray')->visible(fn () => StoreInventory::canAccess())->url(StoreInventory::getUrl())];
    }

    public function table(Table $table): Table
    {
        Gate::forUser(User::findOrFail(auth()->id()))->authorize('history', CardProduct::class);

        return $table->query(app(CardStoreSessionQuery::class)->history(auth()->user(), $this->historyKind))->heading($this->historyKind === 'CLOSE' ? 'Historial de cierres' : 'Historial de aperturas')->columns([
            TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
            TextColumn::make('user.name')->label('Cajera'),
            TextColumn::make('cash_counted_cents')->label($this->historyKind === 'CLOSE' ? 'Efectivo dejado' : 'Efectivo recibido')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact($state))->description(fn (CardStoreCount $record) => 'Esperado: Bs '.Bolivianos::formatCompact($record->cash_expected_cents)),
            ViewColumn::make('differences')->label('Diferencias')->getStateUsing(fn (CardStoreCount $record) => app(CardStoreSessionQuery::class)->differences($record))->view('tarjetas.count-history-differences'),
        ])->filters([
            Filter::make('history')->label('Fecha y cajera')->schema([
                DatePicker::make('from')->label('Desde')->native(),
                DatePicker::make('until')->label('Hasta')->native()->afterOrEqual('from'),
                Select::make('user_id')->label('Cajera')->options(fn () => $this->historyCashiers())->placeholder('Todas')->searchable(),
            ])->query(fn (Builder $query, array $data) => app(CardStoreSessionQuery::class)->filterHistory($query, $data))
                ->indicateUsing(fn (array $data) => array_values(array_filter([
                    filled($data['from'] ?? null) ? 'Desde: '.$data['from'] : null,
                    filled($data['until'] ?? null) ? 'Hasta: '.$data['until'] : null,
                    $this->historyCashiers()[$data['user_id'] ?? ''] ?? null,
                ]))),
        ])->filtersFormColumns(2)->filtersTriggerAction(fn (Action $action) => $action->label('Filtrar')->icon('heroicon-o-funnel')->button()->size('sm'))->recordActions([
            CardInventoryForms::modal(Action::make('detail'))->label('Ver conteo')->modalHeading(fn (CardStoreCount $record) => $record->kind === 'OPEN' ? 'Detalle de apertura' : 'Detalle de cierre')->icon('heroicon-o-eye')->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->modalContent(fn (CardStoreCount $record) => view('tarjetas.store-count-detail', app(CardStoreSessionQuery::class)->detail(auth()->user(), $record->id))),
        ])->paginated([5, 10, 25, 50])->defaultPaginationPageOption(5)->defaultSort(fn (Builder $query) => $query->orderByDesc('created_at')->orderByDesc('id'))->emptyStateHeading($this->historyKind === 'CLOSE' ? 'No hay cierres para esta búsqueda' : 'No hay aperturas para esta búsqueda');
    }
}
