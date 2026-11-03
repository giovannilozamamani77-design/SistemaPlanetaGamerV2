<?php

namespace App\Filament\Pages\Envios;

use App\Domain\Envios\Actions\ConfigureBrazilRatesAction;
use App\Domain\Envios\Actions\SyncExchangeReferenceAction;
use App\Domain\Envios\Authorization\TransferAuthorization;
use App\Domain\Envios\Calculators\BrazilRateCalculator;
use App\Domain\Envios\Queries\BrazilRatesQuery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class BrazilExchangeRates extends Page
{
    protected string $view = 'envios.brazil-rates';

    protected static ?string $title = 'Tasas de cambio · Brasil';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuraciones';

    protected static ?string $navigationLabel = 'Tasas de Brasil';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-americas';

    public ?array $data = [];

    #[Locked]
    public int $expectedId = 0;

    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public ?string $referenceError = null;

    public static function canAccess(): bool
    {
        return UsdExchangeRates::canAccess();
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->refreshReference();
        $p = $this->overview['current'];
        $calc = app(BrazilRateCalculator::class);
        $this->expectedId = $p?->id ?? 0;
        $this->requestKey = (string) Str::uuid();
        $this->form->fill(['base' => $p ? $calc->input($p->base_rate) : null, 'buy_margin' => $p ? $calc->input($p->base_rate - $p->buy_rate) : '0.00',
            'sell_margin' => $p ? $calc->input($p->sell_rate - $p->base_rate) : '0.00', 'amount' => '100', 'cost_source' => $p?->cost_source ?? '',
            'cost_confirmed' => false, 'valid_minutes' => $p?->valid_minutes ?? 10, 'enabled' => $p?->enabled ?? false, 'reference_enabled' => $p?->reference_enabled ?? false]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Base y márgenes por real')->description('La base debe ser el costo confirmado de los fondos. La API solo sirve para comparar.')->columns(2)->schema([
                TextInput::make('base')->label('Base confirmada')->prefix('Bs')->suffix('/ R$ 1')->inputMode('decimal')->required()->live(debounce: 500)->columnSpanFull(),
                TextInput::make('buy_margin')->label('Descontar en compra')->prefix('Bs')->required()->inputMode('decimal')->live(debounce: 500)->helperText('Brasil → Bolivia: compra = base menos este margen.'),
                TextInput::make('sell_margin')->label('Sumar en venta')->prefix('Bs')->required()->inputMode('decimal')->live(debounce: 500)->helperText('Bolivia → Brasil: venta = base más este margen.'),
                TextInput::make('amount')->label('Reales para la simulación')->prefix('R$')->required()->inputMode('decimal')->live(debounce: 500)->columnSpanFull(),
                Textarea::make('cost_source')->label('Fuente / constancia del costo de fondos')->required()->minLength(3)->maxLength(500)->rows(3)->columnSpanFull(),
                Toggle::make('cost_confirmed')->label('Confirmo el costo y la disponibilidad real de fondos')->accepted()->columnSpanFull(),
            ]),
            Section::make('Nuevas cotizaciones')->columns(2)->schema([
                TextInput::make('valid_minutes')->label('Vigencia (minutos)')->integer()->minValue(1)->maxValue(120)->required(),
                Toggle::make('enabled')->label('Habilitar nuevas cotizaciones'),
                Toggle::make('reference_enabled')->label('Actualizar referencia con el programador de tareas')->helperText('Referencia diaria; nunca cambia los precios comerciales automáticamente.')->columnSpanFull(),
            ]),
        ])->statePath('data');
    }

    #[Computed]
    public function overview(): array
    {
        return app(BrazilRatesQuery::class)->overview(auth()->user());
    }

    #[Computed]
    public function preview(): array
    {
        $this->authorizeAdmin();
        try {
            return ['valid' => true, ...app(BrazilRateCalculator::class)->preview((string) ($this->data['base'] ?? ''), (string) ($this->data['buy_margin'] ?? ''), (string) ($this->data['sell_margin'] ?? ''), (string) ($this->data['amount'] ?? ''))];
        } catch (ValidationException $e) {
            return ['valid' => false, 'message' => collect($e->errors())->flatten()->first()];
        }
    }

    public function useReference(): void
    {
        $this->authorizeAdmin();
        $ref = $this->overview['reference'];
        if (! $ref || CarbonImmutable::parse($ref->next_update_at)->isPast()) {
            Notification::make()->title('Actualiza la referencia antes de usarla')->warning()->send();

            return;
        }
        $this->data['base'] = app(BrazilRateCalculator::class)->input((int) $ref->rate);
        $this->data['cost_confirmed'] = false;
        unset($this->preview);
        Notification::make()->title('Referencia copiada como borrador')->body('Verifica el costo real y vuelve a confirmarlo antes de guardar.')->info()->send();
    }

    public function refreshReference(): void
    {
        $this->authorizeAdmin();
        try {
            app(SyncExchangeReferenceAction::class)->execute(auth()->user());
            $this->referenceError = null;
        } catch (ConnectionException|RequestException|ValidationException $e) {
            report($e);
            $this->referenceError = 'No se pudo actualizar la referencia. Se conserva el último dato y las tasas guardadas; intenta de nuevo en un minuto.';
        }
        unset($this->overview);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('referenciaBrasil')->label('Actualizar referencia')->icon('heroicon-o-arrow-path')->action(fn () => $this->refreshReference())];
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $v = $this->form->getState();
        try {
            app(BrazilRateCalculator::class)->preview((string) $v['base'], (string) $v['buy_margin'], (string) $v['sell_margin'], (string) $v['amount']);
            $p = app(ConfigureBrazilRatesAction::class)->execute(auth()->user(), [...$v, 'expected_id' => $this->expectedId, 'request_key' => $this->requestKey]);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['data.'.$k => $m])->all());
        }
        $this->expectedId = $p->id;
        $this->requestKey = (string) Str::uuid();
        $this->data['cost_confirmed'] = false;
        unset($this->overview);
        Notification::make()->title('Tasas de Brasil guardadas')->body('Solo se aplican a cotizaciones nuevas.')->success()->send();
    }

    private function authorizeAdmin(): void
    {
        app(TransferAuthorization::class)->admin(User::findOrFail(auth()->id()));
    }
}
