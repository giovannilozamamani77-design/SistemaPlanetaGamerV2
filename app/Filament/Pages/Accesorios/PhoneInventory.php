<?php

namespace App\Filament\Pages\Accesorios;

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Queries\PhoneInventoryQuery;
use App\Filament\Support\Accesorios\AccessoryCashDepositForms;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class PhoneInventory extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'accesorios.phone-inventory';

    protected static ?string $title = 'Inventario de teléfonos';

    protected static string|\UnitEnum|null $navigationGroup = 'Accesorios';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return AccessoryInventory::canAccess();
    }

    public function canManage(): bool
    {
        return (new AccessoryInventory)->canManage();
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        app(PhoneInventoryQuery::class)->authorize(auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [AccessoryCashDepositForms::history()->visible(fn () => Gate::forUser(auth()->user()->fresh())->allows('viewAny', AccessorySale::class)), Action::make('registerPhone')->label('Registrar teléfono')->icon('heroicon-o-plus')->visible(fn () => $this->canManage())->url(fn () => RegisterPhone::getUrl()), Action::make('pricing')->label('Configurar precios')->icon('heroicon-o-cog-6-tooth')->color('gray')->visible(fn () => PricingConfiguration::canAccess())->url(fn () => PricingConfiguration::getUrl())];
    }

    public function getSummaryProperty(): array
    {
        return app(PhoneInventoryQuery::class)->summary(auth()->user());
    }

    public function table(Table $table): Table
    {
        return $table->query(app(PhoneInventoryQuery::class)->groupForDisplay(app(PhoneInventoryQuery::class)->units(auth()->user())))->columns([
            Stack::make([
                Split::make([
                    ImageColumn::make('image')->label('Foto')->state(fn (PhoneUnit $record) => $record->image_path ? route('accesorios.telefono-imagen', ['phone' => $record->id]) : null)->imageSize(36)->grow(false)->extraAttributes(['class' => 'pg-inventory-thumbnail']),
                    TextColumn::make('variant.model.name')->icon('heroicon-o-device-phone-mobile')->extraAttributes(['class' => 'pg-inventory-value pg-tone-cyan'])->label('Modelo')->state(fn (PhoneUnit $record) => $record->variant->model->brand->name.' '.$record->variant->model->name)->wrap()->weight('bold')->searchable(query: fn (Builder $query, string $search) => app(PhoneInventoryQuery::class)->search($query, $search)),
                ]),
                TextColumn::make('variant.ram_gb')->icon('heroicon-o-cpu-chip')->extraAttributes(['class' => 'pg-inventory-value pg-tone-violet'])->label('Variante')->formatStateUsing(fn ($state, PhoneUnit $record) => $state.' GB / '.$record->variant->storage_gb.' GB · '.$record->color)->wrap()->size('xs'),
                TextColumn::make('condition')->label('Condición')->formatStateUsing(fn ($state) => $state === 'NEW' ? 'Nuevo' : 'Usado')->badge()->color(fn ($state) => $state === 'NEW' ? 'info' : 'warning'),
                TextColumn::make('status')->label('Disponibilidad')->state(fn (PhoneUnit $record) => app(PhoneInventoryQuery::class)->effectiveStatus($record))->formatStateUsing(fn ($state) => ['AVAILABLE' => 'Disponible', 'RESERVED' => 'Reservado', 'SOLD' => 'Vendido'][$state] ?? $state)->badge()->color(fn ($state) => match ($state) {
                    'AVAILABLE' => 'success','RESERVED' => 'warning',default => 'gray'
                }),
                TextColumn::make('group_stock')->label('Stock')->formatStateUsing(fn ($state, PhoneUnit $record) => 'Stock: '.app(PhoneInventoryQuery::class)->availableStock($record))->icon('heroicon-o-cube')->color('success')->weight('bold'),
                TextColumn::make('imeis.imei')->icon('heroicon-o-identification')->extraAttributes(['class' => 'pg-inventory-value pg-tone-gold'])->label('IMEI')->placeholder('Pendiente hasta la venta')->listWithLineBreaks()->size('xs'),
                TextColumn::make('sale_price_cents')->icon('heroicon-o-banknotes')->extraAttributes(['class' => 'pg-inventory-value pg-tone-green'])->label('Precio')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state))->weight('bold')->color('primary'),
            ]),
        ])->contentGrid(['default' => 1, 'sm' => 2, 'md' => 3, 'lg' => 5])->defaultSort('id', 'desc')->paginated([15])->defaultPaginationPageOption(15)->searchPlaceholder('IMEI, marca, modelo, memoria o precio')->filters([SelectFilter::make('condition')->label('Condición')->options(['NEW' => 'Nuevos', 'USED' => 'Usados'])])->recordActions([
            Action::make('detail')->color('info')->label('Detalle del teléfono')->icon('heroicon-o-eye')->iconButton()->tooltip('Ver equipo')->modalWidth(Width::TwoExtraLarge)->modalSubmitAction(false)->modalContent(function (PhoneUnit $record) {
                app(PhoneInventoryQuery::class)->authorize(auth()->user());
                $phone = $record->fresh(['variant.model.brand', 'imeis']);
                $phone->variant->loadCount('availableUnits');

                return view('accesorios.phone-detail', ['phone' => $phone, 'groupStock' => (int) $record->group_stock, 'statusLabel' => ['AVAILABLE' => 'Disponible', 'RESERVED' => 'Reservado', 'SOLD' => 'Vendido'][app(PhoneInventoryQuery::class)->effectiveStatus($phone)] ?? $phone->status, 'canManage' => $this->canManage()]);
            }),
        ])->emptyStateHeading('Registra tu primer teléfono')->emptyStateDescription('Ingresa el stock por modelo; puedes completar el IMEI al vender cada unidad.');
    }
}
