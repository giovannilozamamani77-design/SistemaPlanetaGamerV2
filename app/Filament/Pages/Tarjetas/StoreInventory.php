<?php

namespace App\Filament\Pages\Tarjetas;

use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\SaveCardProductAction;
use App\Domain\Tarjetas\Actions\SaveCardWholesaleRulesAction;
use App\Domain\Tarjetas\Actions\SaveCardWholesaleSelectionAction;
use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Queries\CardStoreInventoryQuery;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Tarjetas\CardCashFundingForms;
use App\Filament\Support\Tarjetas\CardDepositForms;
use App\Filament\Support\Tarjetas\CardInventoryForms;
use App\Filament\Support\Tarjetas\CardStockIntakeActions;
use App\Filament\Support\Tarjetas\CardWholesaleSelectionForm;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class StoreInventory extends Page implements HasTable
{
    use HandlesPersonnelActionErrors;
    use InteractsWithTable;

    public string $company = 'ENTEL';

    #[Locked]
    public bool $priceSettings = false;

    public function showPriceSettings(): void
    {
        app(CardInventoryAuthorization::class)->authorize(auth()->user(), 'update');
        $this->priceSettings = true;
        $this->resetTable();
    }

    public function showStock(): void
    {
        app(CardInventoryAuthorization::class)->authorize(auth()->user(), 'viewAny');
        $this->priceSettings = false;
        $this->resetTable();
    }

    public function selectCompany(string $company): void
    {
        abort_unless(array_key_exists($company, CardInventoryInput::COMPANIES), 422);
        $this->company = $company;
        $this->resetPage($this->getTablePaginationPageName());
    }

    protected string $view = 'tarjetas.store-inventory';

    protected static ?string $title = 'Inventario de tienda';

    protected static string|\UnitEnum|null $navigationGroup = 'Tarjetas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::forUser(auth()->user())->allows('viewAny', CardProduct::class);
    }

    protected function getViewData(): array
    {
        return ['summary' => app(CardStoreInventoryQuery::class)->summary(auth()->user())];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sale')->label('Venta rápida')->icon('heroicon-o-shopping-bag')->visible(fn () => QuickCardSale::canAccess())->url(QuickCardSale::getUrl()),
            ActionGroup::make([
                Action::make('costs')->label('Costos y margen bruto')->icon('heroicon-o-calculator')->visible(fn () => CardCosts::canAccess())->url(CardCosts::getUrl()),
                Action::make('prices')->label('Precios por corte')->icon('heroicon-o-currency-dollar')->visible(fn () => Gate::allows('update', CardProduct::class))->action(fn () => $this->showPriceSettings()),
                CardInventoryForms::modal(Action::make('createProduct'))->label('Agregar producto')->icon('heroicon-o-plus')->color('gray')->visible(fn (): bool => Gate::allows('create', CardProduct::class))
                    ->schema(CardInventoryForms::product())->action(function (array $data): void {
                        app(SaveCardProductAction::class)->execute(auth()->user(), $data);
                        Notification::make()->title('Producto y precios guardados')->success()->send();
                    }),
                $this->wholesaleRulesAction(),
                Action::make('digitalBalances')->label('Saldos digitales Entel')->icon('heroicon-o-device-phone-mobile')->visible(fn () => EntelDigitalBalances::canAccess())->url(EntelDigitalBalances::getUrl()),
                Action::make('depositAccounts')->label('Cuentas para depósitos')->icon('heroicon-o-building-library')->visible(fn () => CardDepositAccounts::canAccess())->url(CardDepositAccounts::getUrl()),
                Action::make('reserves')->label('Resolver sobrantes reservados')->icon('heroicon-o-archive-box')->visible(fn () => CardStoreReserves::canAccess())->url(CardStoreReserves::getUrl()),
                CardInventoryForms::modal(Action::make('wholesaleSelection'))->label('Cortes para venta por mayor')->visible(fn (): bool => Gate::allows('update', CardProduct::class))->icon('heroicon-o-squares-2x2')->modalWidth('5xl')->modalSubmitActionLabel('Guardar selección')
                    ->modalDescription('Marca los cortes que aparecerán al elegir Mayor. También seguirán disponibles en venta por unidad. No cambia precios, stock ni el mínimo de unidades.')
                    ->fillForm(fn (): array => CardWholesaleSelectionForm::values())->schema(fn (): array => CardWholesaleSelectionForm::schema())->action(function (array $data): void {
                        try {
                            app(SaveCardWholesaleSelectionAction::class)->execute(auth()->user(), $data);
                        } catch (ValidationException $exception) {
                            $errors = $exception->errors();
                            if (isset($errors['request_key'])) {
                                $errors['enabled.ENTEL'] = $errors['request_key'];
                                unset($errors['request_key']);
                            }
                            throw ValidationException::withMessages($errors);
                        }
                        Notification::make()->title('Cortes para venta por mayor actualizados')->success()->send();
                    }),
            ])->label('Ajustes')->icon('heroicon-o-cog-6-tooth')->color('gray')->button()->visible(fn (): bool => Gate::allows('update', CardProduct::class) || Gate::allows('create', CardProduct::class) || CardDepositAccounts::canAccess() || EntelDigitalBalances::canAccess() || CardStoreReserves::canAccess()),
            ActionGroup::make([
                CardCashFundingForms::action()->visible(fn () => $this->canOperateFromInventory() && Gate::allows('cashFunding', CardProduct::class)),
                CardStockIntakeActions::purchase()->visible(fn () => $this->canOperateFromInventory() && Gate::allows('purchase', CardProduct::class)),
                CardStockIntakeActions::delivery()->visible(fn () => $this->canOperateFromInventory() && Gate::allows('receive', CardProduct::class)),
                CardDepositForms::action()->visible(fn () => $this->canOperateFromInventory() && Gate::allows('deposit', CardProduct::class)),
            ])->label('Movimientos')->icon('heroicon-o-arrows-right-left')->button()->visible(fn () => $this->canOperateFromInventory()),
            CardInventoryForms::modal(Action::make('initializeStore'))->modalWidth('7xl')->label('Cargar inventario inicial')->icon('heroicon-o-clipboard-document-check')->color('warning')
                ->visible(fn (): bool => Gate::allows('initialize', CardProduct::class) && ! app(CardStoreInventoryQuery::class)->summary(auth()->user())['initialized'])
                ->modalDescription('Registra únicamente lo que realmente hay en tienda. Confirma todos los productos contados antes de guardar; este paso no se repite.')
                ->schema(CardInventoryForms::initialize())->action(function (array $data): void {
                    app(InitializeCardStoreAction::class)->execute(auth()->user(), $data);
                    Notification::make()->title('Conteo inicial registrado')->success()->send();
                }),
            Action::make('stock')->label('Volver al stock')->icon('heroicon-o-arrow-left')->color('gray')->visible(fn () => $this->priceSettings)->action(fn () => $this->showStock()),
            ActionGroup::make([
                Action::make('movements')->label('Movimientos de tienda')->icon('heroicon-o-arrows-right-left')->visible(fn () => InventoryMovements::canAccess())->url(InventoryMovements::getUrl()),
                Action::make('depositsHistory')->label('Depósitos a Administración')->icon('heroicon-o-arrow-up-on-square')->visible(fn () => CardCashDeposits::canAccess())->url(CardCashDeposits::getUrl()),
            ])->label('Consultar registros')->icon('heroicon-o-clock')->color('gray')->button(),
        ];
    }

    protected function canOperateFromInventory(): bool
    {
        return auth()->user()->hasAnyRole(['administrador', 'encargado']);
    }

    protected function wholesaleRulesAction(): Action
    {
        return CardInventoryForms::modal(Action::make('wholesaleRules'))->label('Reglas de mayoreo')->icon('heroicon-o-adjustments-horizontal')->fillForm(fn () => ['minimum' => CardStore::where('code', 'TIENDA')->value('wholesale_min_quantity') ?? 5])->schema([TextInput::make('minimum')->label('Mínimo de unidades combinadas')->integer()->minValue(1)->maxValue(1000000)->required(), CardInventoryForms::key()])->action(function (array $data): void {
            app(SaveCardWholesaleRulesAction::class)->execute(auth()->user(), $data);
            Notification::make()->title('Regla de mayoreo actualizada')->success()->send();
        })->visible(fn () => Gate::allows('update', CardProduct::class));
    }

    protected function usesCardLayout(): bool
    {
        if ($this->priceSettings) {
            app(CardInventoryAuthorization::class)->authorize(auth()->user(), 'update');
        }

        return ! $this->priceSettings;
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => app(CardStoreInventoryQuery::class)->products(auth()->user())->when($this->usesCardLayout(), fn ($query) => $query->where('company', array_key_exists($this->company, CardInventoryInput::COMPANIES) ? $this->company : 'ENTEL')))->heading($this->usesCardLayout() ? null : 'Precios por empresa y corte')->columns($this->usesCardLayout() ? [
            Stack::make([ViewColumn::make('card')->view('tarjetas.inventory-card')]),
        ] : [
            TextColumn::make('company')->label('Empresa')->badge()->color(fn (string $state): string => match ($state) {
                'ENTEL' => 'danger','TIGO' => 'info',default => 'success'
            })->sortable(),
            TextColumn::make('product_name')->label('Producto')->state(fn (CardProduct $record): string => $record->label()),
            TextColumn::make('face_value_cents')->label('Corte')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state))->sortable(),
            TextColumn::make('stock.quantity')->label('Unidades registradas')->numeric(decimalPlaces: 0)->weight('bold'),
            TextColumn::make('purchase_price_cents')->label('Compra')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state)),
            TextColumn::make('normal_price_cents')->label('Normal')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state)),
            TextColumn::make('wholesale_price_cents')->label('Mayorista')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state)),
            TextColumn::make('wholesale_enabled')->label('Mayoreo')->formatStateUsing(fn (bool $state): string => $state ? 'Habilitado' : 'No habilitado'),
            TextColumn::make('is_active')->label('Estado')->formatStateUsing(fn (bool $state): string => $state ? 'Activo' : 'Inactivo')->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray'),
        ])->filters($this->usesCardLayout() ? [TernaryFilter::make('is_active')->label('Estado')] : [SelectFilter::make('company')->label('Empresa')->options(CardInventoryInput::COMPANIES), TernaryFilter::make('is_active')->label('Estado')])
            ->recordActions([CardInventoryForms::modal(Action::make('editProduct'))->label('Precios')->modalHeading(fn (CardProduct $record): string => 'Precios · '.$record->label())->icon('heroicon-o-pencil-square')->visible(fn (): bool => ! $this->usesCardLayout() && Gate::allows('update', CardProduct::class))
                ->fillForm(fn (CardProduct $record): array => CardInventoryForms::productValues($record))->schema(CardInventoryForms::product(true))
                ->action(function (CardProduct $record, array $data): void {
                    app(SaveCardProductAction::class)->execute(auth()->user(), $data, $record);
                    Notification::make()->title('Precios actualizados; historial conservado')->success()->send();
                })])
            ->contentGrid($this->usesCardLayout() ? ['sm' => 2, 'lg' => 3] : null)
            ->paginated([12, 24, 48, 'all'])->defaultPaginationPageOption(24)->defaultSort($this->usesCardLayout() ? 'face_value_cents' : 'company')->emptyStateHeading('Agrega los cortes que tienes en tienda')->emptyStateDescription('Administración o el encargado autorizado preparan el catálogo con sus tres precios.');
    }
}
