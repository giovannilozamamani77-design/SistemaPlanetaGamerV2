<?php

namespace App\Filament\Pages\Tarjetas;

use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Queries\CardStoreInventoryQuery;
use App\Filament\Support\Tarjetas\CardInventoryForms;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class InventoryMovements extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'tarjetas.inventory-movements';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Movimientos de tienda';

    protected static string|\UnitEnum|null $navigationGroup = 'Tarjetas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::forUser(auth()->user())->allows('history', CardProduct::class);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('inventory')->label('Inventario de tienda')->color('gray')->url(fn (): string => StoreInventory::getUrl())->visible(fn (): bool => StoreInventory::canAccess())];
    }

    public function table(Table $table): Table
    {
        return $table->query(app(CardStoreInventoryQuery::class)->movements(auth()->user()))->columns([
            TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->sortable(),
            TextColumn::make('kind')->label('Movimiento')->formatStateUsing(fn (string $state): string => CardStoreInventoryQuery::kinds()[$state] ?? $state)->badge(),
            TextColumn::make('product_snapshot.company')->label('Empresa')->placeholder('—'),
            TextColumn::make('product_snapshot.face_value_cents')->label('Corte')->formatStateUsing(fn ($state): string => 'Bs '.Bolivianos::formatCompact((int) $state))->placeholder('—'),
            TextColumn::make('quantity')->label('Unidades')->numeric(decimalPlaces: 0),
            TextColumn::make('cash_delta_cents')->label('Efectivo')->formatStateUsing(fn (int $state): string => CardStoreInventoryQuery::signedMoney($state))->color(fn (int $state): string => $state < 0 ? 'warning' : ($state > 0 ? 'success' : 'gray')),
            TextColumn::make('user.name')->label('Registró'),
        ])->filters([SelectFilter::make('kind')->label('Movimiento')->options(CardStoreInventoryQuery::kinds())])
            ->recordActions([CardInventoryForms::modal(Action::make('detail'))->label('Detalle')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
                ->fillForm(fn (CardInventoryMovement $record): array => $record->toArray())
                ->schema([Section::make('Movimiento registrado')->columns(2)->schema([
                    TextEntry::make('kind')->label('Tipo')->formatStateUsing(fn (string $state): string => CardStoreInventoryQuery::kinds()[$state] ?? $state),
                    TextEntry::make('reference')->label('Referencia / número de operación')->placeholder('Sin referencia'),
                    TextEntry::make('destination_snapshot.bank')->label('Banco destino')->placeholder('—'),
                    TextEntry::make('destination_snapshot.number')->label('Cuenta destino')->placeholder('—'),
                    TextEntry::make('destination_snapshot.holder')->label('Titular destino')->placeholder('—'),
                    TextEntry::make('quantity')->label('Unidades'), TextEntry::make('unit_cost_cents')->label('Costo unitario real')->formatStateUsing(fn ($state): string => 'Bs '.Bolivianos::formatCompact((int) $state))->placeholder('No informado'),
                    TextEntry::make('total_cost_cents')->label('Costo total')->formatStateUsing(fn ($state): string => 'Bs '.Bolivianos::formatCompact((int) $state))->placeholder('No informado'),
                    TextEntry::make('cash_delta_cents')->label('Cambio de efectivo')->formatStateUsing(fn (int $state): string => CardStoreInventoryQuery::signedMoney($state)),
                    TextEntry::make('stock_before')->label('Tarjetas antes')->placeholder('—'), TextEntry::make('stock_after')->label('Tarjetas después')->placeholder('—'),
                    TextEntry::make('cash_before_cents')->label('Efectivo antes')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state)),
                    TextEntry::make('cash_after_cents')->label('Efectivo después')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state)),
                ])])])->paginated([5, 10, 25, 50])->defaultPaginationPageOption(5)->defaultSort('id', 'desc')->emptyStateHeading('Todavía no hay movimientos')->emptyStateDescription('Los conteos iniciales, compras y entregas aparecerán aquí.');
    }
}
