<?php

namespace App\Filament\Pages\Tarjetas;

use App\Domain\Tarjetas\Actions\RecordEntelDigitalFundingAction;
use App\Domain\Tarjetas\Actions\SaveEntelRechargeRatesAction;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Models\EntelDigitalMovement;
use App\Domain\Tarjetas\Queries\EntelDigitalQuery;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Tarjetas\CardInventoryForms;
use App\Filament\Support\Tarjetas\EntelDigitalForms;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EntelDigitalBalances extends Page implements HasTable
{
    use HandlesPersonnelActionErrors;
    use InteractsWithTable;

    protected string $view = 'tarjetas.entel-digital-balances';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Saldos digitales Entel';

    protected static string|\UnitEnum|null $navigationGroup = 'Tarjetas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?int $navigationSort = 4;

    protected ?array $digitalAccounts = null;

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::forUser(auth()->user())->allows('viewAny', EntelDigitalAccount::class);
    }

    protected function accounts(): array
    {
        return $this->digitalAccounts ??= app(EntelDigitalQuery::class)->accounts(auth()->user());
    }

    protected function getViewData(): array
    {
        return ['accounts' => $this->accounts()];
    }

    protected function getHeaderActions(): array
    {
        $actions = [Action::make('costs')->label('Costos y margen bruto')->icon('heroicon-o-calculator')->visible(fn () => CardCosts::canAccess())->url(CardCosts::getUrl())];
        foreach (EntelDigitalAccount::KINDS as $kind => $label) {
            foreach (['INITIAL' => 'Saldo inicial', 'TOP_UP' => 'Registrar carga'] as $operation => $verb) {
                $initial = $operation === 'INITIAL';
                $actions[] = CardInventoryForms::modal(Action::make($operation.'_'.$kind))->label($verb.' · '.$label)
                    ->color($initial ? 'gray' : 'primary')->icon($initial ? 'heroicon-o-clipboard-document-check' : 'heroicon-o-plus-circle')
                    ->visible(fn (): bool => Gate::allows('record', EntelDigitalAccount::class) && ($initial ? ! isset($this->accounts()[$kind]) : isset($this->accounts()[$kind])))
                    ->schema(EntelDigitalForms::funding($initial))->action(function (array $data) use ($kind, $operation): void {
                        app(RecordEntelDigitalFundingAction::class)->execute(auth()->user(), array_replace($data, ['kind' => $kind, 'operation' => $operation]));
                        $this->digitalAccounts = null;
                        Notification::make()->title('Saldo digital registrado; historial conservado')->success()->send();
                    });
            }
        }

        $actions[] = CardInventoryForms::modal(Action::make('rechargeRates'))->label('Bonos del cliente')->icon('heroicon-o-cog-6-tooth')->color('gray')->visible(fn () => Gate::allows('record', EntelDigitalAccount::class) && Gate::allows('update', CardProduct::class))
            ->fillForm(function (): array {
                $s = CardStore::where('code', 'TIENDA')->first();

                return ['credit' => Bolivianos::formatCompact($s?->entel_credit_bonus_bps ?? 800), 'packages' => Bolivianos::formatCompact($s?->entel_packages_bonus_bps ?? 1500), 'request_key' => (string) Str::uuid()];
            })
            ->schema([TextInput::make('credit')->label('Crédito · bono del cliente')->suffix('%')->required(), TextInput::make('packages')->label('Paquetes · bono del cliente')->suffix('%')->required(), Text::make('No es la bonificación de las cargas del negocio. Solo aplica a recargas futuras; no cambia su importe cobrado ni descontado. Mínimo fijo Bs 100.'), CardInventoryForms::key()])
            ->action(function (array $data): void {
                app(SaveEntelRechargeRatesAction::class)->execute(auth()->user(), $data);
                Notification::make()->title('Bonificaciones futuras actualizadas')->success()->send();
            });

        $actions[] = Action::make('inventory')->label('Volver al inventario')->icon('heroicon-o-arrow-left')->color('gray')->visible(fn () => StoreInventory::canAccess())->url(StoreInventory::getUrl());

        return $actions;
    }

    public function table(Table $table): Table
    {
        return $table->query(app(EntelDigitalQuery::class)->movements(auth()->user()))->heading('Historial de saldos y cargas')->columns([
            TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
            TextColumn::make('account.kind')->label('Saldo')->formatStateUsing(fn (string $state): string => EntelDigitalAccount::KINDS[$state])->badge(),
            TextColumn::make('operation')->label('Registro')->formatStateUsing(fn (string $state): string => match ($state) {
                'INITIAL' => 'Saldo inicial','TOP_UP' => 'Carga','RECHARGE' => 'Recarga vendida','COST_SET' => 'Costo completado',default => $state
            }),
            TextColumn::make('paid_cents')->label('Pagado')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state))->placeholder('No corresponde'),
            TextColumn::make('credited_cents')->label('Acreditado')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state)),
            TextColumn::make('debited_cents')->label('Consumido por recargas')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state)),
            TextColumn::make('bonus_cents')->label('Bonificación')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state))->placeholder('No corresponde'),
            TextColumn::make('balance_after_cents')->label('Saldo posterior')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state)),
            TextColumn::make('user.name')->label('Registró'),
        ])->filters([
            SelectFilter::make('kind')->label('Tipo de saldo')->options(EntelDigitalAccount::KINDS)->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($query, $kind) => $query->whereHas('account', fn ($account) => $account->where('kind', $kind)))),
            SelectFilter::make('operation')->label('Registro')->options(['INITIAL' => 'Saldo inicial', 'TOP_UP' => 'Carga', 'RECHARGE' => 'Recarga vendida', 'COST_SET' => 'Costo completado']),
        ])->recordActions([
            CardInventoryForms::modal(Action::make('detail'))->label('Detalle')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
                ->fillForm(fn (EntelDigitalMovement $record): array => $record->toArray())->schema([
                    TextEntry::make('reference')->label('Referencia')->placeholder('Sin referencia'),
                    TextEntry::make('balance_before_cents')->label('Saldo antes')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state)),
                    TextEntry::make('balance_after_cents')->label('Saldo después')->formatStateUsing(fn (int $state): string => 'Bs '.Bolivianos::formatCompact($state)),
                    TextEntry::make('bonus_rate_bps')->label('Porcentaje real de la bonificación')->formatStateUsing(fn (int $state): string => Bolivianos::format($state).' %')->placeholder('No corresponde al saldo inicial'),
                ]),
        ])->paginated([5, 10, 25, 50])->defaultPaginationPageOption(5)->defaultSort('id', 'desc')->emptyStateHeading('Sin registros digitales')->emptyStateDescription('Registra el saldo real inicial de Crédito y Paquetes por separado.');
    }
}
