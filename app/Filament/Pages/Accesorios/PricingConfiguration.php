<?php

namespace App\Filament\Pages\Accesorios;

use App\Domain\Accesorios\Actions\RefreshPricingReferenceAction;
use App\Domain\Accesorios\Actions\SavePricingSettingsAction;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\PricingChange;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\PricingQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class PricingConfiguration extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'accesorios.pricing-configuration';

    protected static ?string $title = 'Tipo de cambio global';

    protected static string|\UnitEnum|null $navigationGroup = 'Accesorios';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 0;

    public array $dollar = [];

    #[Locked]
    public ?string $referenceWarning = null;

    public static function canAccess(): bool
    {
        $user = auth()->user()?->fresh();

        return $user && Gate::forUser($user)->allows('configure', PricingSettings::class);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        $this->fillDollar();
    }

    private function fillDollar(): void
    {
        $values = app(PricingQuery::class)->form(auth()->user());
        $this->form->fill(['adjustment' => $values['adjustment'], 'expected_revision' => $values['expected_revision'], 'request_key' => (string) Str::uuid()]);
    }

    public function loadReference(): void
    {
        app(PricingQuery::class)->authorize(auth()->user());
        try {
            app(RefreshPricingReferenceAction::class)->execute(auth()->user(), false, true);
            $this->referenceWarning = null;
        } catch (ValidationException) {
            $this->referenceWarning = 'No se pudo consultar el dólar. Se conserva la última lectura; vuelve a intentar en un minuto.';
        }
    }

    protected function getViewData(): array
    {
        return app(PricingQuery::class)->dollarPreview(auth()->user(), $this->dollar['adjustment'] ?? null);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('dollar')->schema([
            TextInput::make('adjustment')->label('Diferencia por dólar · Bs')->inputMode('decimal')->required()->live(debounce: 500),
            Hidden::make('expected_revision'), Hidden::make('request_key'),
        ]);
    }

    public function saveDollar(): void
    {
        try {
            app(SavePricingSettingsAction::class)->executeDollar(auth()->user(), $this->form->getState());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['dollar.adjustment' => collect($e->errors())->flatten()->first()]);
        }
        $this->fillDollar();
        Notification::make()->title('Diferencia global guardada')->body('Se aplica a las conversiones en USD de Accesorios y Teléfonos.')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('refreshReference')->label('Actualizar dólar')->icon('heroicon-o-arrow-path')->color('gray')->action(function (): void {
            try {
                app(RefreshPricingReferenceAction::class)->execute(auth()->user(), true);
                $this->referenceWarning = null;
                Notification::make()->title('Dólar actualizado')->success()->send();
            } catch (ValidationException $e) {
                $this->referenceWarning = collect($e->errors())->flatten()->first();
                Notification::make()->title('Se conserva el último valor')->warning()->send();
            }
        })];
    }

    public function configureAction(): Action
    {
        $sections = [];
        foreach (PricingInput::PREFIXES as $kind => $prefix) {
            $sections[] = Section::make(PricingInput::KINDS[$kind])->schema([
                ToggleButtons::make($prefix.'_mode')->label('Precio de venta')->options(['MANUAL' => 'Manual', 'PERCENT' => 'Porcentaje sobre costo'])->inline()->required()->live()->afterStateUpdated(function ($state, Set $set) use ($kind): void {
                    if ($kind === 'NEW_PHONE' && $state === 'MANUAL') {
                        $set('new_phone_auto_update', false);
                    }
                }),
                TextInput::make($prefix.'_percent')->label('Porcentaje')->suffix('%')->inputMode('decimal')->visible(fn (Get $get) => $get($prefix.'_mode') === 'PERCENT')->required(fn (Get $get) => $get($prefix.'_mode') === 'PERCENT'),
            ]);
        }
        $sections[] = Toggle::make('new_phone_auto_update')->label('Actualizar precios automáticos de teléfonos nuevos')->helperText('Disponible al elegir porcentaje para teléfonos nuevos. Los precios manuales y las ventas anteriores quedan fijos.')->disabled(fn (Get $get) => $get('new_phone_mode') !== 'PERCENT')->dehydrated()->default(false);
        $sections[] = Hidden::make('expected_revision');
        $sections[] = Hidden::make('request_key');

        return Action::make('configure')->label('Editar porcentajes')->modalHeading('Precios de venta')->modalWidth('3xl')->modalSubmitActionLabel('Guardar')->fillForm(fn () => app(PricingQuery::class)->form(auth()->user()) + ['request_key' => (string) Str::uuid()])->schema($sections)->action(function (array $data): void {
            try {
                app(SavePricingSettingsAction::class)->executeRules(auth()->user(), $data);
            } catch (ValidationException $e) {
                $path = $this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data';
                $errors = [];
                foreach ($e->errors() as $field => $messages) {
                    $errors[$path.'.'.$field] = $messages;
                }
                if (isset($e->errors()['expected_revision']) || isset($e->errors()['request_key'])) {
                    Notification::make()->title('Revisa la configuración')->body(collect($e->errors())->flatten()->first())->warning()->send();
                }
                throw ValidationException::withMessages($errors);
            }
            $this->fillDollar();
            Notification::make()->title('Precios de venta configurados')->success()->send();
        });
    }

    public function table(Table $table): Table
    {
        return $table->query(app(PricingQuery::class)->history(auth()->user()))->heading('Historial de configuración')->columns([
            TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i', timezone: 'America/La_Paz'),
            TextColumn::make('after.revision')->label('Revisión'),
            TextColumn::make('actor_name')->label('Registrado por'),
            TextColumn::make('after.exchange_mode')->label('Dólar')->formatStateUsing(fn ($state) => $state === 'PHYSICAL' ? 'Físico manual' : 'Referencia + ajuste')->badge(),
        ])->recordActions([Action::make('detail')->label('Ver cambio')->icon('heroicon-o-eye')->iconButton()->tooltip('Ver configuración anterior y nueva')->modalSubmitAction(false)->modalContent(fn (PricingChange $record) => view('accesorios.pricing-change', ['event' => $record]))])->defaultSort('id', 'desc')->paginated([5, 10, 25])->defaultPaginationPageOption(5)->emptyStateHeading('Sin cambios de configuración');
    }
}
