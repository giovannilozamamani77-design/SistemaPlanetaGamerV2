<?php

namespace App\Filament\Pages\Tarjetas;

use App\Domain\Tarjetas\Actions\ResolveCardStoreReserveAction;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStoreReserve;
use App\Filament\Support\Tarjetas\CardInventoryForms;
use App\Models\User;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CardStoreReserves extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'tarjetas.store-count-history';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Sobrantes reservados';

    protected static string|\UnitEnum|null $navigationGroup = 'Tarjetas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::allows('initialize', CardProduct::class);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('inventory')->label('Volver al inventario')->icon('heroicon-o-arrow-left')->color('gray')->visible(fn () => StoreInventory::canAccess())->url(StoreInventory::getUrl())];
    }

    public function table(Table $table): Table
    {
        Gate::forUser(User::findOrFail(auth()->id()))->authorize('initialize', CardProduct::class);

        return $table->query(CardStoreReserve::query()->with('product'))->columns([
            TextColumn::make('created_at')->label('Hallazgo')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
            TextColumn::make('product.label')->label('Componente')->getStateUsing(fn (CardStoreReserve $r) => $r->product?->label() ?? 'Efectivo'),
            TextColumn::make('held_amount')->label('Reservado')->formatStateUsing(fn ($state, CardStoreReserve $record) => $record->card_product_id ? $state.' unidades' : 'Bs '.Bolivianos::formatCompact($state)),
            TextColumn::make('expires_at')->label('Liberación automática')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->placeholder('Solo Administración'),
            TextColumn::make('released_at')->label('Resuelto / liberado')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->placeholder('Pendiente'),
        ])->recordActions([
            CardInventoryForms::modal(Action::make('resolve'))->label('Resolver')->icon('heroicon-o-adjustments-horizontal')->visible(fn (CardStoreReserve $record) => ! $record->released_at && $record->held_amount > 0)->modalHeading('Resolver sobrante reservado')->modalDescription('Incorporar lo deja disponible. Devolver retira el sobrante reservado sin descontarlo otra vez del disponible. No anula faltantes.')->schema([
                Select::make('method')->label('Resolución')->options(['INCORPORAR' => 'Incorporar al disponible', 'DEVOLVER' => 'Devolver sobrante'])->required(),
                TextInput::make('amount')->label('Monto en Bs o cantidad de tarjetas')->required(), TextInput::make('reason')->label('Motivo')->required()->maxLength(2000), Hidden::make('request_key')->default(fn () => (string) Str::uuid()),
            ])->action(fn (CardStoreReserve $record, array $data) => app(ResolveCardStoreReserveAction::class)->execute(auth()->user(), $record->id, $data))->successNotificationTitle('Resolución registrada'),
        ])->paginated([5, 10, 25, 50])->defaultPaginationPageOption(5)->defaultSort('id', 'desc');
    }
}
