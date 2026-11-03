<?php

namespace App\Livewire\Accesorios;

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class AccessorySaleHistory extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions,InteractsWithSchemas,InteractsWithTable;

    public function mount(): void
    {
        app(AccessorySaleQuery::class)->authorize(auth()->user(), 'viewAny');
    }

    public function table(Table $table): Table
    {
        return $table->query(app(AccessorySaleQuery::class)->history(auth()->user()))->columns([
            TextColumn::make('id')->icon('heroicon-o-receipt-percent')->extraAttributes(['class' => 'pg-history-value pg-history-violet'])->label('Venta')->prefix('#'), TextColumn::make('created_at')->icon('heroicon-o-clock')->extraAttributes(['class' => 'pg-history-value pg-history-cyan'])->label('Fecha')->dateTime('d/m/Y H:i', timezone: 'America/La_Paz'), TextColumn::make('actor_name')->icon('heroicon-o-user')->extraAttributes(['class' => 'pg-history-value pg-history-gold'])->label('Atendió'), ViewColumn::make('products')->label('Productos')->view('accesorios.sale-history-products'), TextColumn::make('total_cents')->icon('heroicon-o-banknotes')->extraAttributes(['class' => 'pg-history-value pg-history-green'])->label('Total · Bs')->formatStateUsing(fn ($state) => Bolivianos::formatCompact((int) $state)),
        ])->recordActions([Action::make('receipt')->color('primary')->label('Recibo')->icon('heroicon-o-printer')->iconButton()->tooltip('Ver o imprimir recibo guardado')->url(fn (AccessorySale $record) => route('accesorios.recibo', ['sale' => $record->id]))->openUrlInNewTab(), Action::make('detail')->color('info')->label('Detalle')->icon('heroicon-o-eye')->iconButton()->tooltip('Ver venta registrada')->modalSubmitAction(false)->modalContent(fn (AccessorySale $record) => view('accesorios.sale-detail', ['sale' => app(AccessorySaleQuery::class)->detail(auth()->user(), $record)]))])->paginated([5, 10, 25])->defaultPaginationPageOption(5)->emptyStateHeading('Sin ventas registradas');
    }

    public function render(): View
    {
        app(AccessorySaleQuery::class)->authorize(auth()->user(), 'viewAny');

        return view('accesorios.stock-entry-history');
    }
}
