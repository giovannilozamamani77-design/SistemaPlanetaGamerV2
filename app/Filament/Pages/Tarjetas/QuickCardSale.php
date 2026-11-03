<?php

namespace App\Filament\Pages\Tarjetas;

use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordEntelRechargeAction;
use App\Domain\Tarjetas\Calculators\CardSaleCalculator;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSaleRegisterEntry;
use App\Domain\Tarjetas\Queries\CardSaleRegisterQuery;
use App\Domain\Tarjetas\Queries\CardSalesQuery;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Domain\Tarjetas\Queries\EntelRechargeQuery;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Tarjetas\CardCashFundingForms;
use App\Filament\Support\Tarjetas\CardDepositForms;
use App\Filament\Support\Tarjetas\CardInventoryForms;
use App\Filament\Support\Tarjetas\CardSaleForms;
use App\Filament\Support\Tarjetas\CardStockIntakeActions;
use App\Filament\Support\Tarjetas\CardStoreSessionForms;
use App\Filament\Support\Tarjetas\EntelRechargeForms;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class QuickCardSale extends Page implements HasTable
{
    use HandlesPersonnelActionErrors;
    use InteractsWithTable;

    protected string $view = 'tarjetas.quick-card-sale';

    protected static ?string $title = 'Venta rápida';

    protected static string|\UnitEnum|null $navigationGroup = 'Tarjetas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 0;

    public array $data = [];

    public array $quantities = [];

    public array $recharge = [];

    protected ?array $catalog = null;

    protected ?array $saleSessionStatus = null;

    protected array $registerDetails = [];

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::forUser(auth()->user())->allows('sell', CardProduct::class);
    }

    protected function currentSaleSession(): array
    {
        return $this->saleSessionStatus ??= app(CardStoreSessionQuery::class)->status(auth()->user());
    }

    public function getHeading(): string
    {
        $name = $this->currentSaleSession()['session']?->user?->name;

        return $name ? 'Tarjetas · '.$name : 'Tarjetas';
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        if (in_array(request()->query('action'), ['openStore', 'closeStore'], true)) {
            $this->defaultAction = null;
            $this->redirect(request()->query('action') === 'openStore' ? CardStoreOpening::getUrl() : CardStoreClosing::getUrl());
        }
        $this->form->fill();
        $this->resetQuantities();
        $this->rechargeForm->fill();
    }

    public function saleCatalog(): array
    {
        return $this->catalog ??= app(CardSalesQuery::class)->products(auth()->user())->get()->mapWithKeys(fn ($p) => [$p->id => ['company' => $p->company, 'cut_label' => $p->cutLabel(), 'label' => $p->label(), 'quantity' => $p->stock?->quantity ?? 0, 'normal_price_cents' => $p->normal_price_cents, 'wholesale_price_cents' => $p->wholesale_price_cents, 'wholesale_enabled' => $p->wholesale_enabled]])->all();
    }

    protected function getViewData(): array
    {
        $catalog = $this->saleCatalog();
        $calculator = app(CardSaleCalculator::class);
        $cart = [];
        foreach ((array) ($this->data['lines'] ?? []) as $key => $line) {
            $product = $catalog[$line['product_id'] ?? 0] ?? null;
            if (! $product) {
                continue;
            }
            $cart[$key] = ['line' => $line, 'product' => $product, 'preview' => $calculator->preview([$line])];
        }

        $isWholesale = ($this->data['mode'] ?? 'NORMAL') === 'WHOLESALE';

        return ['turnCompanySummary' => app(CardSaleRegisterQuery::class)->currentTurnSummary(auth()->user()), 'cardSessionStatus' => $this->currentSaleSession(), 'rechargeAvailability' => $isWholesale ? app(EntelRechargeQuery::class)->availability(auth()->user(), (string) ($this->recharge['kind'] ?? 'CREDIT'), $this->recharge['amount'] ?? null) : null,
            'rechargePreview' => $isWholesale ? app(EntelRechargeQuery::class)->preview(auth()->user(), (string) ($this->recharge['kind'] ?? 'CREDIT'), $this->recharge['amount'] ?? null) : null,
            'summary' => app(CardSalesQuery::class)->summary(auth()->user()),
            'groups' => collect($catalog)->where('quantity', '>', 0)->when(($this->data['mode'] ?? 'NORMAL') === 'WHOLESALE', fn ($products) => $products->where('wholesale_enabled', true))->groupBy('company', true), 'cart' => $cart,
            'preview' => $calculator->preview(array_values(array_column($cart, 'line')))];
    }

    protected function resetQuantities(): void
    {
        $this->quantities = [];
        foreach (array_keys($this->saleCatalog()) as $id) {
            $this->quantities['product_'.$id] = '';
        }
    }

    public function clearCart(): void
    {
        $this->form->fill();
        $this->resetQuantities();
        $this->resetErrorBag();
    }

    public function updatedQuantities(mixed $value, string $key): void
    {
        if (! preg_match('/\Aproduct_([0-9]+)\z/', $key, $match)) {
            return;
        }
        $id = (int) $match[1];
        $product = $this->saleCatalog()[$id] ?? null;
        if (! $product) {
            throw ValidationException::withMessages(['quantities.'.$key => 'Este producto no está disponible.']);
        }
        if (($this->data['mode'] ?? 'NORMAL') === 'WHOLESALE' && ! $product['wholesale_enabled'] && filled($value) && $value !== 0 && $value !== '0') {
            throw ValidationException::withMessages(['quantities.'.$key => 'No habilitado para mayorista.']);
        }
        $previous = null;
        $lines = [];
        foreach ((array) ($this->data['lines'] ?? []) as $lineKey => $line) {
            if (empty($line['product_id'])) {
                continue;
            }
            if ((int) $line['product_id'] === $id) {
                $previous = $line;

                continue;
            }
            $lines[$lineKey] = $line;
        }
        if ($value !== '' && $value !== null && $value !== 0 && $value !== '0') {
            $lines[$key] = ['product_id' => $id, 'quantity' => $value, 'unit_price' => $previous['unit_price'] ?? Bolivianos::formatCompact($product[($this->data['mode'] ?? 'NORMAL') === 'WHOLESALE' ? 'wholesale_price_cents' : 'normal_price_cents'])];
        }
        $this->data['lines'] = $lines;
        $this->resetErrorBag();
    }

    public function adjustQuantity(int $id, int $delta): void
    {
        abort_unless(in_array($delta, [-1, 1], true), 422);
        $product = $this->saleCatalog()[$id] ?? null;
        if (! $product) {
            throw ValidationException::withMessages(['data.lines' => 'El producto ya no está disponible.']);
        }
        $key = 'product_'.$id;
        $quantity = filter_var($this->quantities[$key] ?? 0, FILTER_VALIDATE_INT);
        $next = max(0, ($quantity === false ? 0 : $quantity) + $delta);
        if ($delta > 0 && $next > $product['quantity']) {
            throw ValidationException::withMessages(['quantities.'.$key => 'Stock insuficiente: quedan '.$product['quantity'].' unidades.']);
        }
        $this->updatedQuantities($next, $key);
        $this->quantities[$key] = $next === 0 ? '' : $next;
    }

    public function chooseMode(string $mode): void
    {
        abort_unless(in_array($mode, ['NORMAL', 'WHOLESALE'], true), 422);
        if (($this->data['mode'] ?? 'NORMAL') === $mode) {
            return;
        }
        $this->data['mode'] = $mode;
        $catalog = $this->saleCatalog();
        foreach ((array) ($this->data['lines'] ?? []) as $key => $line) {
            $p = $catalog[$line['product_id'] ?? 0] ?? null;
            if (! $p) {
                continue;
            }
            if ($mode === 'WHOLESALE' && ! $p['wholesale_enabled']) {
                unset($this->data['lines'][$key]);
                $this->quantities['product_'.$line['product_id']] = '';

                continue;
            }
            $this->data['lines'][$key]['unit_price'] = Bolivianos::formatCompact($p[$mode === 'WHOLESALE' ? 'wholesale_price_cents' : 'normal_price_cents']);
        }
        $this->resetErrorBag();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->schema(CardSaleForms::schema());
    }

    protected function authorizeSaleSession(string $field): void
    {
        if (! app(CardStoreSessionQuery::class)->status(auth()->user())['is_owner']) {
            throw ValidationException::withMessages([$field => 'Abre tu sesión de Tarjetas antes de registrar ventas o recargas.']);
        }
    }

    public function registerQuickSale(int $id, mixed $quantity, string $requestKey): void
    {
        $this->authorizeSaleSession('data.lines');
        if ($requestKey !== ($this->data['request_key'] ?? null)) {
            return;
        }
        $key = 'product_'.$id;
        $this->updatedQuantities($quantity, $key);
        $this->quantities[$key] = $quantity;
        $this->registerSale();
    }

    public function registerSale(): void
    {
        $this->authorizeSaleSession('data.lines');
        $lineKeys = array_keys((array) ($this->data['lines'] ?? []));
        $data = $this->form->getState();
        try {
            $sale = app(RecordCardSaleAction::class)->execute(auth()->user(), $data);
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $field => $errors) {
                if (preg_match('/\Alines\.(\d+)(\..*)?\z/', $field, $match) && array_key_exists((int) $match[1], $lineKeys)) {
                    $field = 'lines.'.$lineKeys[(int) $match[1]].($match[2] ?? '');
                }
                if ($field === 'request_key') {
                    $field = 'lines';
                }
                $messages['data.'.$field] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }
        $this->catalog = null;
        $this->clearCart();
        $this->resetPage($this->getTablePaginationPageName());
        Notification::make()->title('Venta #'.$sale->id.' registrada · Bs '.Bolivianos::formatCompact($sale->total_cents))->success()->send();
    }

    public function rechargeForm(Schema $schema): Schema
    {
        return $schema->statePath('recharge')->columns(2)->schema(EntelRechargeForms::inline());
    }

    public function chooseRechargeKind(string $kind): void
    {
        abort_unless(($this->data['mode'] ?? 'NORMAL') === 'WHOLESALE', 403);
        abort_unless(in_array($kind, ['CREDIT', 'PACKAGES'], true), 422);
        $this->clearRecharge();
        $this->recharge['kind'] = $kind;
    }

    public function clearRecharge(): void
    {
        $this->rechargeForm->fill();
        $this->resetErrorBag();
    }

    public function registerRecharge(): void
    {
        $this->authorizeSaleSession('recharge.amount');
        abort_unless(($this->data['mode'] ?? 'NORMAL') === 'WHOLESALE', 403);
        $data = $this->rechargeForm->getState();
        try {
            $sale = app(RecordEntelRechargeAction::class)->execute(auth()->user(), $data);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors['recharge.'.($field === 'request_key' ? 'amount' : $field)] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->clearRecharge();
        $this->resetPage($this->getTablePaginationPageName());
        Notification::make()->title('Recarga #'.$sale->id.' registrada · Bs '.Bolivianos::formatCompact($sale->amount_cents))->success()->send();
    }

    protected function refreshStockCatalog(): void
    {
        $this->catalog = null;
    }

    public function openStoreAction(): Action
    {
        return CardStoreSessionForms::action('OPEN')->visible(false);
    }

    public function closeStoreAction(): Action
    {
        return CardStoreSessionForms::action('CLOSE');
    }

    protected function getHeaderActions(): array
    {
        return [ActionGroup::make([
            CardCashFundingForms::action()->visible(fn () => $this->currentSaleSession()['is_owner'] && Gate::allows('cashFunding', CardProduct::class)),
            CardStockIntakeActions::delivery(fn () => $this->refreshStockCatalog())->visible(fn () => $this->currentSaleSession()['is_owner'] && Gate::allows('receive', CardProduct::class)),
            CardStockIntakeActions::purchase(fn () => $this->refreshStockCatalog())->visible(fn () => $this->currentSaleSession()['is_owner'] && Gate::allows('purchase', CardProduct::class)),
            CardDepositForms::action()->visible(fn () => $this->currentSaleSession()['is_owner'] && Gate::allows('deposit', CardProduct::class)),
        ])->label('Movimientos')->icon('heroicon-o-arrows-right-left')->button()->visible(fn () => $this->currentSaleSession()['is_owner']), $this->openStoreAction(), $this->closeStoreAction()];
    }

    protected function registerEntryDetail(CardSaleRegisterEntry $record): array
    {
        return $this->registerDetails[$record->id] ??= app(CardSaleRegisterQuery::class)->companyDetail(auth()->user(), $record);
    }

    public function table(Table $table): Table
    {
        return $table->query(app(CardSaleRegisterQuery::class)->companyEntries(auth()->user()))->heading('Registro por empresa')->groups([Group::make('company')->label('Empresa')->getTitleFromRecordUsing(fn ($record) => CardInventoryInput::COMPANIES[$record->company] ?? 'Sin empresa registrada')])->defaultGroup('company')->groupingSettingsHidden()->columns([
            TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->description(fn (CardSaleRegisterEntry $record) => '#'.$record->source_id.' · '.$record->user?->name),
            TextColumn::make('mode')->label('Operación')->formatStateUsing(fn ($state) => match ($state) {
                'NORMAL' => 'Tarjetas · Normal', 'WHOLESALE' => 'Tarjetas · Mayorista', 'CREDIT' => 'Recarga · Crédito', 'PACKAGES' => 'Recarga · Paquetes', default => $state
            })->badge()->color(fn ($state) => match ($state) {
                'CREDIT' => 'info', 'PACKAGES' => 'primary', default => 'success'
            })->icon(fn ($state) => match ($state) {
                'CREDIT' => 'heroicon-o-device-phone-mobile', 'PACKAGES' => 'heroicon-o-cube', default => 'heroicon-o-ticket'
            }),
            ViewColumn::make('products')->label('Empresa · corte · cantidad')->view('tarjetas.sale-register-products'),
            TextColumn::make('total_cents')->label('Total')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact($state)),
        ])->filters([
            Filter::make('history')->label('Buscar ventas')->schema([
                DatePicker::make('from')->label('Desde')->native()->displayFormat('d/m/Y'),
                DatePicker::make('until')->label('Hasta')->native()->displayFormat('d/m/Y')->afterOrEqual('from'),
                Select::make('company')->label('Empresa')->options(CardInventoryInput::COMPANIES)->placeholder('Todas'),
                Select::make('type')->label('Tipo de venta')->options(['CARD' => 'Tarjetas', 'CREDIT' => 'Crédito', 'PACKAGES' => 'Paquetes'])->placeholder('Todos'),
            ])->query(fn (Builder $query, array $data) => app(CardSaleRegisterQuery::class)->filterHistory($query, $data))
                ->indicateUsing(fn (array $data) => array_values(array_filter([
                    filled($data['from'] ?? null) ? 'Desde: '.$data['from'] : null,
                    filled($data['until'] ?? null) ? 'Hasta: '.$data['until'] : null,
                    CardInventoryInput::COMPANIES[$data['company'] ?? ''] ?? null,
                    ['CARD' => 'Tarjetas', 'CREDIT' => 'Crédito', 'PACKAGES' => 'Paquetes'][$data['type'] ?? ''] ?? null,
                ]))),
        ])->filtersFormColumns(2)->filtersTriggerAction(fn (Action $action) => $action->label('Filtrar historial')->button()->size('sm'))->recordActions([CardInventoryForms::modal(Action::make('detail'))->label('Detalle')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')->fillForm(fn (CardSaleRegisterEntry $record) => $this->registerEntryDetail($record))->schema([
            TextEntry::make('customer_total_cents')->label('Recibió el cliente')->placeholder('No corresponde')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact($state)),
            TextEntry::make('bonus_rate_bps')->label('Bono Entel aplicado')->placeholder('No corresponde')->formatStateUsing(fn ($state) => Bolivianos::format($state).' %'),
            RepeatableEntry::make('lines')->state(fn (CardSaleRegisterEntry $record) => $this->registerEntryDetail($record)['lines'] ?? [])->label('Productos vendidos')->schema([
                TextEntry::make('product_snapshot.label')->label('Producto'), TextEntry::make('quantity')->label('Cantidad'),
                TextEntry::make('unit_price_cents')->label('Precio aplicado')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact($state)),
                TextEntry::make('total_cents')->label('Subtotal')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact($state)),
            ])->columns(2),
        ])])->paginated([5, 10, 25, 50])->defaultPaginationPageOption(5)->defaultSort(fn ($query) => $query->orderBy('created_at', 'desc')->orderBy('source_id', 'desc')->orderBy('entry_type'))->emptyStateHeading('Todavía no hay ventas');
    }
}
