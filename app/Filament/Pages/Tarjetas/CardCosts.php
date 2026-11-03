<?php

namespace App\Filament\Pages\Tarjetas;

use App\Domain\Tarjetas\Actions\ValueRemainingCardCostAction;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Queries\CardCostsQuery;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Tarjetas\CardInventoryForms;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CardCosts extends Page implements HasTable
{
    use HandlesPersonnelActionErrors;
    use InteractsWithTable;

    protected string $view = 'tarjetas.card-costs';

    protected static ?string $title = 'Costos de Tarjetas y Entel';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::allows('initialize', CardProduct::class) && Gate::allows('viewAny', EntelDigitalAccount::class);
    }

    protected function getViewData(): array
    {
        $query = app(CardCostsQuery::class);

        return ['books' => $query->digital(auth()->user()), 'margins' => $query->margins(auth()->user())];
    }

    protected function getHeaderActions(): array
    {
        $actions = [Action::make('inventory')->label('Inventario')->icon('heroicon-o-arrow-left')->url(StoreInventory::getUrl())];
        foreach (EntelDigitalAccount::KINDS as $kind => $label) {
            $actions[] = $this->valuation(Action::make('value_'.$kind))->label('Completar costo · '.$label)
                ->visible(fn () => Gate::allows('record', EntelDigitalAccount::class) && EntelDigitalAccount::where('kind', $kind)->where('balance_cents', '>', 0)->whereNull('cost_value_cents')->exists())
                ->fillForm(fn (): array => $this->values(EntelDigitalAccount::where('kind', $kind)->firstOrFail(), 'DIGITAL'))
                ->action(fn (array $data) => $this->saveCost($data));
        }

        return $actions;
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => app(CardCostsQuery::class)->stocks(auth()->user()))->columns([
            TextColumn::make('product.company')->label('Empresa')->badge()->searchable(),
            TextColumn::make('product.face_value_cents')->label('Corte')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state)),
            TextColumn::make('quantity')->label('Stock disponible')->numeric(decimalPlaces: 0),
            TextColumn::make('cost_value_cents')->label('Costo de lo que queda')->state(fn (CardStock $record) => $record->quantity === 0 ? 0 : $record->cost_value_cents)->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::formatCompact((int) $state))->placeholder('Pendiente de costo')->color(fn ($state) => $state === null ? 'warning' : 'success'),
        ])->recordActions([
            $this->valuation(Action::make('valueCost'))->label('Completar costo')->visible(fn (CardStock $record) => Gate::allows('initialize', CardProduct::class) && $record->quantity > 0 && $record->cost_value_cents === null)
                ->fillForm(fn (CardStock $record): array => $this->values($record, 'PHYSICAL'))
                ->action(fn (array $data) => $this->saveCost($data)),
        ])->paginated([15, 30, 60])->defaultPaginationPageOption(15)->defaultSort('id');
    }

    protected function valuation(Action $action): Action
    {
        return CardInventoryForms::modal($action)->icon('heroicon-o-calculator')->modalHeading('Completar costo real pendiente')->modalSubmitActionLabel('Confirmar valoración')
            ->modalDescription('Ingresa el costo total real de las existencias restantes, con su respaldo. No es un precio de venta ni un pago. No cambia efectivo, cantidades ni ganancias de ventas anteriores.')->schema([
                Hidden::make('type'), Hidden::make('id'), Hidden::make('expected_quantity'), Hidden::make('expected_revision'), Hidden::make('request_key'),
                TextInput::make('available')->label('Existencias que se valoran')->disabled()->dehydrated(false),
                TextInput::make('total_cost')->label('Costo total de estas existencias · Bs')->required()->inputMode('decimal'),
                TextInput::make('reason')->label('Respaldo / referencia del costo')->required()->maxLength(160),
            ]);
    }

    protected function values(CardStock|EntelDigitalAccount $record, string $type): array
    {
        $quantity = $type === 'PHYSICAL' ? $record->quantity : $record->balance_cents;

        return ['type' => $type, 'id' => $record->id, 'expected_quantity' => $quantity, 'expected_revision' => $record->cost_revision, 'available' => $type === 'PHYSICAL' ? $quantity.' unidades' : 'Bs '.Bolivianos::formatCompact($quantity), 'request_key' => (string) Str::uuid()];
    }

    protected function saveCost(array $data): void
    {
        app(ValueRemainingCardCostAction::class)->execute(auth()->user(), $data);
        Notification::make()->title('Costo registrado; saldos e historial conservados')->success()->send();
    }
}
