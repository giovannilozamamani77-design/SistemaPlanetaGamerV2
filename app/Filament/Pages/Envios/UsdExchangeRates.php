<?php

namespace App\Filament\Pages\Envios;

use App\Domain\Envios\Actions\ConfigureUsdRatesAction;
use App\Domain\Envios\Actions\SyncUsdReferenceAction;
use App\Domain\Envios\Authorization\TransferAuthorization;
use App\Domain\Envios\Calculators\UsdRateCalculator;
use App\Domain\Envios\Queries\UsdRatesQuery;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

class UsdExchangeRates extends Page
{
    protected string $view = 'envios.usd-rates';

    protected static ?string $title = 'Tasas de cambio';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuraciones';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    public ?array $data = [];

    #[Locked]
    public int $expectedId = 0;

    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public ?string $referenceError = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return config('envios.enabled', false) && $user && $user->is_active && $user->hasRole('administrador') && $user->can('envios.administrar');
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->refreshReference();
        $current = $this->overview['current'];
        $calc = app(UsdRateCalculator::class);
        $this->expectedId = $current?->id ?? 0;
        $this->requestKey = (string) Str::uuid();
        $this->form->fill(['buy' => $current ? $calc->input($current->buy_rate) : null, 'sell' => $current ? $calc->input($current->sell_rate) : null, 'amount' => '100', 'reason' => '']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Precios de Planeta Gamer')->description('Bolivianos por 1 dólar. Solo Administración puede guardar cambios.')->columns(2)->schema([
                TextInput::make('buy')->label('Compra: pagamos al cliente')->prefix('Bs')->suffix('/ USD 1')->required()->live(debounce: 500)->inputMode('decimal')->helperText('Precio al que recibimos dólares del cliente.'),
                TextInput::make('sell')->label('Venta: cobramos al cliente')->prefix('Bs')->suffix('/ USD 1')->required()->live(debounce: 500)->inputMode('decimal')->helperText('Precio al que entregamos dólares al cliente.'),
                TextInput::make('amount')->label('Dólares para la simulación')->prefix('USD')->required()->live(debounce: 500)->inputMode('decimal')->helperText('Solo vista previa; no registra cambio de dinero.')->columnSpanFull(),
                Textarea::make('reason')->label('Motivo del cambio')->required()->minLength(3)->maxLength(500)->rows(3)->columnSpanFull()->helperText('Por ejemplo, comparación de mercado revisada. No ingreses contraseñas ni datos privados.'),
            ]),
        ])->statePath('data');
    }

    #[Computed]
    public function overview(): array
    {
        return app(UsdRatesQuery::class)->overview(auth()->user());
    }

    #[Computed]
    public function preview(): array
    {
        $this->authorizeAdmin();
        try {
            return ['valid' => true, ...app(UsdRateCalculator::class)->preview((string) ($this->data['buy'] ?? ''), (string) ($this->data['sell'] ?? ''), (string) ($this->data['amount'] ?? ''))];
        } catch (ValidationException $e) {
            return ['valid' => false, 'message' => collect($e->errors())->flatten()->first()];
        }
    }

    public function refreshReference(): void
    {
        $this->authorizeAdmin();
        try {
            app(SyncUsdReferenceAction::class)->execute(auth()->user());
            $this->referenceError = null;
        } catch (ConnectionException|RequestException|ValidationException $e) {
            $this->referenceError = 'No se pudo actualizar la referencia. Se conserva el último dato disponible; tus precios no cambiaron. Intenta de nuevo en un minuto.';
            report($e);
        }
        unset($this->overview);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('actualizarReferenciaUsd')->label('Actualizar referencia')->icon('heroicon-o-arrow-path')->action(fn () => $this->refreshReference())];
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $v = $this->form->getState();
        try {
            app(UsdRateCalculator::class)->preview((string) $v['buy'], (string) $v['sell'], (string) $v['amount']);
            $record = app(ConfigureUsdRatesAction::class)->execute(auth()->user(), ['buy' => (string) $v['buy'], 'sell' => (string) $v['sell'], 'reason' => $v['reason'], 'expected_id' => $this->expectedId, 'request_key' => $this->requestKey]);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
        }
        $this->expectedId = $record->id;
        $this->requestKey = (string) Str::uuid();
        $this->data['reason'] = '';
        unset($this->overview);
        Notification::make()->title('Tasas del dólar guardadas')->body('La referencia de la API no modifica estos precios.')->success()->send();
    }

    private function authorizeAdmin(): void
    {
        app(TransferAuthorization::class)->admin(User::findOrFail(auth()->id()));
    }
}
