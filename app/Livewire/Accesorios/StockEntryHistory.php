<?php

namespace App\Livewire\Accesorios;

use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Queries\AccessoryInventoryQuery;
use App\Support\Bolivianos;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class StockEntryHistory extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    #[Locked]
    public int $productId;

    public function mount(int $productId): void
    {
        $this->productId = $productId;
        app(AccessoryInventoryQuery::class)->authorize(auth()->user(), 'manage');
    }

    public function table(Table $table): Table
    {
        return $table->query(app(AccessoryInventoryQuery::class)->entries(auth()->user(), AccessoryProduct::findOrFail($this->productId)))->columns([
            TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i', timezone: 'America/La_Paz'), TextColumn::make('kind')->label('Entrada')->formatStateUsing(fn ($state) => $state === 'INITIAL' ? 'Inicial' : 'Compra')->badge(), TextColumn::make('quantity')->label('Unidades'), TextColumn::make('unit_cost_cents')->label('Costo unitario · Bs')->formatStateUsing(fn ($state) => Bolivianos::formatCompact((int) $state)), TextColumn::make('total_cost_cents')->label('Total · Bs')->formatStateUsing(fn ($state) => Bolivianos::formatCompact((int) $state)), TextColumn::make('supplier')->label('Proveedor')->placeholder('—')->wrap(), TextColumn::make('actor_name')->label('Registró'), TextColumn::make('note')->label('Observación')->wrap(),
        ])->paginated([5, 10, 25])->defaultPaginationPageOption(5)->emptyStateHeading('Sin entradas registradas');
    }

    public function render(): View
    {
        app(AccessoryInventoryQuery::class)->authorize(auth()->user(), 'manage');

        return view('accesorios.stock-entry-history');
    }
}
