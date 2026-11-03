<?php

namespace App\Filament\Pages\Accesorios;

use App\Domain\Accesorios\Actions\SaveCommercialRulesAction;
use App\Domain\Accesorios\Models\CommercialRuleChange;
use App\Domain\Accesorios\Models\CommercialRules;
use App\Domain\Accesorios\Models\PricingSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SalesRulesConfiguration extends Page
{
    protected string $view = 'accesorios.sales-rules-configuration';

    protected static ?string $title = 'Descuentos y comisiones';

    protected static string|\UnitEnum|null $navigationGroup = 'Comisiones';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $u = auth()->user()?->fresh();

        return $u && $u->hasRole('administrador') && Gate::forUser($u)->allows('configure', PricingSettings::class);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        app(SaveCommercialRulesAction::class)->authorize(auth()->user());
    }

    public function rulesFor(string $scope): array
    {
        app(SaveCommercialRulesAction::class)->authorize(auth()->user());

        return CommercialRules::current($scope);
    }

    public function changes(): Collection
    {
        app(SaveCommercialRulesAction::class)->authorize(auth()->user());

        return CommercialRuleChange::orderByDesc('id')->limit(5)->get();
    }

    public function discountsAction(): Action
    {
        return $this->editor('discounts', 'DISCOUNTS', 'Configurar descuentos');
    }

    public function commissionsAction(): Action
    {
        return $this->editor('commissions', 'COMMISSIONS', 'Configurar comisiones');
    }

    private function editor(string $name, string $scope, string $title): Action
    {
        return Action::make($name)->label($title)->icon('heroicon-o-cog-6-tooth')->modalHeading($title)->modalWidth(Width::ThreeExtraLarge)->stickyModalFooter()->fillForm(function () use ($scope): array {
            app(SaveCommercialRulesAction::class)->authorize(auth()->user());
            $current = CommercialRules::current($scope);
            $rules = $current['rules'];

            return ['expected_revision' => $current['revision'], 'request_key' => (string) Str::uuid(), 'enabled' => $rules['enabled'] ?? true, 'phone_percent' => number_format(($rules['phone_bps'] ?? 0) / 100, 2, '.', ''), 'phone_max' => number_format(($rules['phone_max_cents'] ?? 0) / 100, 2, '.', ''), 'ranges' => array_map(fn ($r) => ['from' => number_format($r['from_cents'] / 100, 2, '.', ''), 'amount' => number_format($r['amount_cents'] / 100, 2, '.', '')], $rules['ranges'])];
        })->schema([
            Hidden::make('expected_revision'), Hidden::make('request_key'),
            Toggle::make('enabled')->label('Generar comisiones en nuevas ventas')->helperText('Al pausar, se conservan las comisiones ya ganadas y las ventas siguen funcionando.')->visible($scope === 'COMMISSIONS'),
            Section::make('Accesorios · rangos por precio unitario del catálogo')->compact()->description('Cada rango empieza en Desde y termina antes del siguiente. La rebaja no cambia el rango de comisión.')->schema([
                Repeater::make('ranges')->label('Rangos')->schema([TextInput::make('from')->label('Desde · Bs')->required()->inputMode('decimal'), TextInput::make('amount')->label($scope === 'DISCOUNTS' ? 'Rebaja máxima por unidad · Bs' : 'Comisión por unidad · Bs')->required()->inputMode('decimal')])->columns(2)->minItems(1)->maxItems(20)->reorderable(false)->addActionLabel('Agregar rango'),
            ]),
            Section::make('Teléfonos')->compact()->description($scope === 'DISCOUNTS' ? 'Nunca se vende por debajo del costo, aunque exista descuento disponible.' : 'Porcentaje sobre la ganancia después de la rebaja. No se descuenta del efectivo cobrado.')->schema([
                TextInput::make('phone_max')->label('Descuento máximo por teléfono')->prefix('Bs')->inputMode('decimal')->required()->visible($scope === 'DISCOUNTS'),
                TextInput::make('phone_percent')->label('Comisión sobre la ganancia final')->suffix('%')->inputMode('decimal')->required()->visible($scope === 'COMMISSIONS'),
            ]),
        ])->action(function (array $data) use ($scope): void {
            app(SaveCommercialRulesAction::class)->execute(auth()->user(), $data + ['scope' => $scope]);
            Notification::make()->title('Configuración guardada')->body('Se aplica a nuevas ventas. El historial se conserva.')->success()->send();
        });
    }
}
