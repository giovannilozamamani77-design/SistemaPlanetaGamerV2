<?php

namespace App\Filament\Pages\Tarjetas;

use App\Domain\Tarjetas\Actions\ReviewCardSaleCancellation;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSaleCancellationRequest;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class CardSaleCancellations extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'tarjetas.inventory-movements';

    protected static ?string $title = 'Solicitudes de anulación';

    protected static string|\UnitEnum|null $navigationGroup = 'Tarjetas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?int $navigationSort = 7;

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::forUser(auth()->user()->fresh())->allows('cancelSale', CardProduct::class);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('sales')->label('Historial de ventas')->url(fn () => QuickCardSale::getUrl())->icon('heroicon-o-shopping-bag')];
    }

    public function table(Table $table): Table
    {
        Gate::authorize('cancelSale', CardProduct::class);

        return $table->query(CardSaleCancellationRequest::with(['sale', 'requester', 'reviewer']))->columns([
            TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
            TextColumn::make('card_sale_id')->label('Venta')->prefix('#'),
            TextColumn::make('requester.name')->label('Solicitó'),
            TextColumn::make('sale.total_quantity')->label('Tarjetas'),
            TextColumn::make('sale.total_cents')->label('Importe')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact($state)),
            TextColumn::make('reason')->label('Motivo')->wrap(),
            TextColumn::make('status')->label('Estado')->badge(),
            TextColumn::make('reviewer.name')->label('Revisó'),
        ])->recordActions([
            Action::make('approve')->label('Aprobar')->color('danger')->requiresConfirmation()
                ->modalDescription('Confirma que las tarjetas están en tienda y que se devolvió el dinero si hubo cobro. Se revertirá la venta completa.')
                ->visible(fn (CardSaleCancellationRequest $record) => $record->status === 'PENDIENTE')
                ->action(fn (CardSaleCancellationRequest $record) => app(ReviewCardSaleCancellation::class)->decide(auth()->user(), $record->id, true)),
            Action::make('deny')->label('Negar')->requiresConfirmation()
                ->visible(fn (CardSaleCancellationRequest $record) => $record->status === 'PENDIENTE')
                ->action(fn (CardSaleCancellationRequest $record) => app(ReviewCardSaleCancellation::class)->decide(auth()->user(), $record->id, false)),
        ])->defaultSort('id', 'desc')->paginated([10, 25, 50])->emptyStateHeading('No hay solicitudes de anulación');
    }
}
