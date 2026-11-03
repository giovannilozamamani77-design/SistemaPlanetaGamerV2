<?php

namespace App\Filament\Support\Tarjetas;

use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Filament\Pages\Tarjetas\CardStoreClosing;
use App\Filament\Pages\Tarjetas\CardStoreOpening;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Gate;

class CardStoreSessionForms
{
    public static function schema(string $kind): array
    {
        $open = $kind === 'OPEN';
        $stocks = ViewField::make('stocks')->label('Conteo de tarjetas por empresa')->columnSpanFull()->view('tarjetas.store-count-cuts')->viewData(['onlyStocked' => true]);
        $cash = Grid::make(['default' => 1, 'md' => 2])->columnSpanFull()->schema([
            CardInventoryForms::money('cash', 'Efectivo contado')->required(false)->live(debounce: 350)->suffixActions([Action::make($open ? 'openingCashCount' : 'closingCashCount')->label('Realizar arqueo')->icon('heroicon-o-calculator')->button()->action(fn ($livewire) => $livewire->openCashCount())]),
            View::make($open ? 'tarjetas.store-opening-cash' : 'tarjetas.store-count-summary')->viewData(fn (Get $get) => app(CardStoreSessionQuery::class)->preview(auth()->user(), $kind, ['cash' => $get('cash'), 'stocks' => $get('stocks'), 'reason' => $get('reason')])),
        ]);

        return [
            Hidden::make('store_revision'),
            $stocks, $cash,
            TextInput::make('reason')->label($open ? 'Observación (si corresponde)' : 'Si hay sobrante, explica brevemente')->maxLength(2000)->live(debounce: 350)->columnSpanFull(),
            CardInventoryForms::key(),
        ];
    }

    public static function action(string $kind): Action
    {
        $open = $kind === 'OPEN';

        return Action::make($open ? 'openStore' : 'closeStore')->label($open ? 'Abrir Tarjetas' : 'Cerrar Tarjetas')->icon($open ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')->color($open ? 'success' : 'danger')
            ->url($open ? CardStoreOpening::getUrl() : CardStoreClosing::getUrl())
            ->visible(function () use ($open): bool {
                if (! Gate::allows($open ? 'openSession' : 'closeSession', CardProduct::class)) {
                    return false;
                }
                $status = app(CardStoreSessionQuery::class)->status(auth()->user());

                return $status['initialized'] && ($open ? ! $status['session'] : $status['is_owner']);
            });
    }
}
