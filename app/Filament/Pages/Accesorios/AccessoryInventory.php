<?php

namespace App\Filament\Pages\Accesorios;

use App\Domain\Accesorios\Actions\ReceiveAccessoryStockAction;
use App\Domain\Accesorios\Actions\ResolveAccessoryCostAction;
use App\Domain\Accesorios\Actions\UpdateAccessoryPriceAction;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Queries\AccessoryInventoryQuery;
use App\Filament\Support\Accesorios\AccessoryCashDepositForms;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccessoryInventory extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'accesorios.inventory';

    protected static ?string $title = 'Inventario de accesorios';

    protected static string|\UnitEnum|null $navigationGroup = 'Accesorios';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $u = auth()->user()?->fresh();

        return $u && Gate::forUser($u)->allows('viewAny', AccessoryProduct::class);
    }

    public function canManage(): bool
    {
        $u = auth()->user()?->fresh();

        return $u && Gate::forUser($u)->allows('manage', AccessoryProduct::class);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        app(AccessoryInventoryQuery::class)->authorize(auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [
            AccessoryCashDepositForms::history()->visible(fn () => Gate::forUser(auth()->user()->fresh())->allows('viewAny', AccessorySale::class)),
            Action::make('createProduct')->label('Nuevo accesorio')->icon('heroicon-o-plus')->visible(fn () => $this->canManage())->url(fn () => RegisterAccessoryProduct::getUrl()),
            Action::make('categories')->label('Categorías')->icon('heroicon-o-squares-2x2')->color('primary')->visible(fn () => $this->canManage())->url(fn () => AccessoryCategories::getUrl()),
            Action::make('pricing')->label('Configurar precios')->icon('heroicon-o-cog-6-tooth')->color('gray')->visible(fn () => PricingConfiguration::canAccess())->url(fn () => PricingConfiguration::getUrl()),
        ];
    }

    private function validateAction(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $e) {
            $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors[$path.'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    public function table(Table $table): Table
    {
        return $table->query(app(AccessoryInventoryQuery::class)->products(auth()->user()))->columns([Stack::make([
            Split::make([
                ImageColumn::make('image')->label('Imagen')->state(fn (AccessoryProduct $record) => $record->image_path ? route('accesorios.producto-imagen', ['product' => $record->id]) : null)->imageSize(36)->grow(false)->extraAttributes(['class' => 'pg-inventory-thumbnail']),
                TextColumn::make('name')->icon('heroicon-o-cube')->extraAttributes(['class' => 'pg-inventory-value pg-tone-cyan'])->label('Accesorio')->searchable(['name', 'description', 'code', 'category'])->wrap()->weight('bold'),
            ]),
            TextColumn::make('cost_status')->state(fn (AccessoryProduct $record) => $this->canManage() && $record->cost_pending ? 'Costo de compra pendiente' : null)->badge()->color('warning'),
            Grid::make(2)->schema([
                TextColumn::make('sale_price_cents')->icon('heroicon-o-banknotes')->extraAttributes(['class' => 'pg-inventory-value pg-tone-green'])->label('Precio de venta · Bs')->prefix('Bs ')->weight('bold')->searchable(query: fn (Builder $query, string $search) => app(AccessoryInventoryQuery::class)->searchSalePrice($query, $search))->formatStateUsing(fn ($state) => Bolivianos::formatCompact((int) $state))->color(fn (AccessoryProduct $record) => app(AccessoryInventoryQuery::class)->belowCost($record) ? 'danger' : 'primary'),
                TextColumn::make('quantity')->icon('heroicon-o-archive-box')->extraAttributes(['class' => 'pg-inventory-value pg-tone-gold'])->label('Stock')->prefix('Stock: ')->badge()->color(fn (AccessoryProduct $record) => $record->quantity <= $record->minimum_stock ? 'warning' : 'success')->sortable(),
            ]),
        ])->space(1)])->contentGrid(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 5, 'xl' => 5])->searchPlaceholder('Código, nombre, categoría o precio')->recordActions([
            Action::make('resolveCost')->label('Registrar costo real')->icon('heroicon-o-banknotes')
                ->visible(fn (AccessoryProduct $record) => $this->canManage() && $record->cost_pending)
                ->modalDescription('Indica el costo por unidad del inventario inicial. Se calculará el costo de las unidades vendidas y de las que siguen en stock.')
                ->schema([Hidden::make('expected_revision'), TextInput::make('unit_cost')->label('Costo real por unidad · Bs')->required()->inputMode('decimal')])
                ->fillForm(fn (AccessoryProduct $record) => ['expected_revision' => $record->revision])
                ->action(function (AccessoryProduct $record, array $data): void {
                    $this->validateAction(fn () => app(ResolveAccessoryCostAction::class)->execute(auth()->user(), $record->id, $data['unit_cost'], (int) $data['expected_revision']));
                    Notification::make()->title('Costo real registrado')->success()->send();
                }),
            Action::make('updatePrice')->color('warning')->label('Ajustar precio')->icon('heroicon-o-tag')->iconButton()->tooltip('Confirmar precio manual o sugerencia configurada')->visible(fn (AccessoryProduct $record) => $this->canManage() && ! $record->cost_pending)->modalHeading('Actualizar precio de venta')->modalDescription('No cambia el costo de las compras ni la configuración general. El cambio queda registrado.')->schema([
                Hidden::make('request_key')->default(fn () => (string) Str::uuid()), Hidden::make('expected_revision'), Hidden::make('expected_pricing_revision'), Select::make('price_mode')->label('Precio')->options(['MANUAL' => 'Manual', 'PERCENT' => 'Porcentaje configurado'])->required()->native(), TextInput::make('manual_price')->label('Precio manual · Bs')->inputMode('decimal')->helperText('Solo se utiliza en modo manual; debe cubrir el costo promedio.'),
            ])->fillForm(fn (AccessoryProduct $record) => app(AccessoryInventoryQuery::class)->priceForm(auth()->user(), $record) + ['request_key' => (string) Str::uuid()])->action(function (AccessoryProduct $record, array $data): void {
                $event = $this->validateAction(fn () => app(UpdateAccessoryPriceAction::class)->execute(auth()->user(), array_replace($data, ['product_id' => $record->id])));
                Notification::make()->title('Precio actualizado · Bs '.Bolivianos::formatCompact($event->new_price_cents))->success()->send();
            }),
            Action::make('receive')->color('success')->label('Registrar entrada')->icon('heroicon-o-arrow-down-tray')->iconButton()->tooltip('Registrar entrada')->visible(fn (AccessoryProduct $record) => $this->canManage() && ! $record->cost_pending)->modalHeading(fn (AccessoryProduct $record) => 'Recibir · '.$record->name)->modalDescription('Conserva el costo de esta entrada y recalcula el promedio. No registra el pago ni descuenta de otras cajas.')->schema([
                Hidden::make('request_key')->default(fn () => (string) Str::uuid()), Hidden::make('expected_revision'), Select::make('kind')->label('Tipo de entrada')->options(['INITIAL' => 'Inventario inicial', 'PURCHASE' => 'Compra'])->required()->native(), TextInput::make('quantity')->label('Cantidad recibida')->required()->integer()->minValue(1)->maxValue(100000), TextInput::make('unit_cost')->label('Costo real por unidad · Bs')->required()->inputMode('decimal'), TextInput::make('supplier')->label('Proveedor (opcional)')->maxLength(160), Textarea::make('note')->label('Observación (opcional)')->maxLength(500),
            ])->fillForm(fn (AccessoryProduct $record) => ['kind' => $record->revision === 0 ? 'INITIAL' : 'PURCHASE', 'expected_revision' => $record->revision, 'request_key' => (string) Str::uuid()])->action(function (AccessoryProduct $record, array $data): void {
                $event = $this->validateAction(fn () => app(ReceiveAccessoryStockAction::class)->execute(auth()->user(), array_replace($data, ['product_id' => $record->id])));
                Notification::make()->title('Entrada registrada · Bs '.Bolivianos::formatCompact($event->total_cost_cents))->success()->send();
                if (app(AccessoryInventoryQuery::class)->belowCost($record->fresh())) {
                    Notification::make()->title('El precio confirmado está por debajo del nuevo costo promedio')->body('Revisa el precio antes de vender; no se cambió automáticamente.')->warning()->send();
                }
            }),
            Action::make('detail')->color('info')->label('Costo y compras')->icon('heroicon-o-clock')->iconButton()->tooltip('Costo, sugerencia e historial de compras')->visible(fn () => $this->canManage())->modalWidth(Width::FiveExtraLarge)->modalSubmitAction(false)->modalContent(function (AccessoryProduct $record) {
                $q = app(AccessoryInventoryQuery::class);
                $q->authorize(auth()->user(), 'manage');
                $record = $record->fresh();
                $suggestion = null;
                $warning = null;
                try {
                    $suggestion = $record->cost_pending ? null : $q->suggestion(auth()->user(), $record);
                } catch (ValidationException $e) {
                    $warning = collect($e->errors())->flatten()->first();
                }

                return view('accesorios.inventory-detail', ['product' => $record, 'average' => $q->cost($record), 'suggestion' => $suggestion, 'warning' => $warning]);
            }),
        ])->defaultSort('name')->paginated([15])->defaultPaginationPageOption(15)->emptyStateHeading('Registra tu primer accesorio')->emptyStateDescription('Crea el producto y registra las cantidades reales con su costo inicial.');
    }
}
