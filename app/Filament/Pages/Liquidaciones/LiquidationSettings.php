<?php

namespace App\Filament\Pages\Liquidaciones;

use App\Domain\Cajas\Actions\SetCashBoxCommissionAccountAction;
use App\Domain\Liquidaciones\Actions\SetLiquidationRulesAction;
use App\Domain\Liquidaciones\Queries\LiquidationAccountQuery;
use App\Domain\Liquidaciones\Queries\LiquidationRulesQuery;
use App\Filament\Support\Cajas\HandlesCashBoxActionErrors;
use App\Filament\Support\Liquidaciones\LiquidationAccountForm;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;

class LiquidationSettings extends Page
{
    use HandlesCashBoxActionErrors;

    protected string $view = 'liquidaciones.settings';

    protected static ?string $title = 'Configuración de liquidaciones';

    protected static ?string $slug = 'liquidaciones/configuracion';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return (auth()->user()?->hasRole('administrador') ?? false) && (auth()->user()?->can('cajas.catalogo.gestionar') ?? false);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    #[Computed]
    public function accounts(): Collection
    {
        return app(LiquidationAccountQuery::class)->catalog(auth()->user());
    }

    #[Computed]
    public function boxes(): array
    {
        return app(LiquidationRulesQuery::class)->forAdmin(auth()->user());
    }

    #[Computed]
    public function demoAccount(): ?object
    {
        return app(LiquidationAccountQuery::class)->demo(auth()->user());
    }

    public function accountSettingsAction(): Action
    {
        return Action::make('accountSettings')->label('Agregar cuenta de destino')->icon('heroicon-o-plus')->modalHeading('Cuenta de destino de liquidaciones')
            ->modalWidth('3xl')->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar cuenta')->modalCancelActionLabel('Cancelar')
            ->fillForm(function (array $arguments): array {
                $account = isset($arguments['account_id']) ? $this->accounts->firstWhere('id', (int) $arguments['account_id']) : null;
                if (isset($arguments['account_id']) && ! $account) {
                    abort(404);
                }

                return ['account_id' => $account?->id, 'name' => $account?->name, 'holder_name' => $account?->holder_name,
                    'destination' => $account?->destination, 'qr_path' => $account?->qr_path,
                    'pin' => '', 'reason' => '', 'request_key' => (string) Str::uuid()];
            })
            ->schema(LiquidationAccountForm::fields())->action(function (array $data): void {
                app(SetCashBoxCommissionAccountAction::class)->execute(auth()->user(), $data);
                unset($this->accounts, $this->boxes);
                Notification::make()->success()->title('Cuenta de destino guardada')->send();
            });
    }

    public function configureBoxAction(): Action
    {
        return Action::make('configureBox')->label('Configurar origen, destino y turnos')->modalHeading('Liquidación de esta caja')->modalWidth('2xl')
            ->stickyModalHeader()->stickyModalFooter()->modalSubmitActionLabel('Guardar para próximas aperturas')->modalCancelActionLabel('Cancelar')
            ->fillForm(function (array $arguments): array {
                $box = $this->boxes[(int) ($arguments['box_id'] ?? 0)] ?? abort(404);

                return ['destination_key' => $box['destination_key'], 'source' => $box['source'], 'source_reference' => $box['source_reference'], 'morning' => $box['morning'], 'afternoon' => $box['afternoon'],
                    'expected_revision' => $box['revision'], 'request_key' => (string) Str::uuid(), 'reason' => 'Configuración de liquidación de la caja'];
            })->schema(fn (array $arguments): array => [
                Select::make('source')->label('Cuenta de la que se descuenta')->options($this->boxes[(int) ($arguments['box_id'] ?? 0)]['options'] ?? [])->required()->live()
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('source_reference', $this->boxes[(int) ($arguments['box_id'] ?? 0)]['accounts'][$state]['reference'] ?? null)),
                TextInput::make('source_reference')->label('Número o referencia de la cuenta de origen')->maxLength(120),
                Select::make('destination_key')->label('Cuenta de destino')->options(fn (): array => $this->accounts->mapWithKeys(fn (object $account): array => [$account->account_key => $account->name.' · '.$account->destination])->all())->required()->helperText('Elige el destino de esta caja. La selección se aplica a próximas aperturas.'),
                Toggle::make('morning')->label('Liquidar al cerrar turno mañana'),
                Toggle::make('afternoon')->label('Liquidar al cerrar turno tarde'),
                Textarea::make('reason')->label('Motivo')->required()->minLength(3)->maxLength(2000),
                Hidden::make('expected_revision'), Hidden::make('request_key'),
            ])->action(function (array $data, array $arguments): void {
                app(SetLiquidationRulesAction::class)->execute(auth()->user(), (int) ($arguments['box_id'] ?? 0), $data);
                unset($this->boxes);
                Notification::make()->success()->title('Reglas guardadas para próximas aperturas')->send();
            });
    }
}
