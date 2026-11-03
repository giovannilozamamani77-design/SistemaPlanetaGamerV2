<?php

namespace App\Filament\Resources\Incidencias\GlobalRecoveries\Pages;

use App\Domain\Incidencias\Actions\DeclareGlobalRecoveryAction;
use App\Domain\Incidencias\Actions\SaveRepaymentSettingsAction;
use App\Domain\Incidencias\Queries\RepaymentPanelQuery;
use App\Domain\Incidencias\Queries\RepaymentSettingsQuery;
use App\Filament\Resources\Incidencias\GlobalRecoveries\GlobalRecoveryResource;
use App\Filament\Resources\Incidencias\GlobalRecoveries\Schemas\GlobalRecoveryForm;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Personal\PersonnelModal;
use App\Filament\Support\Personal\PersonnelTable;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class ManageGlobalRecoveries extends ManageRecords
{
    use HandlesPersonnelActionErrors;

    protected string $view = 'incidencias.repayment-panel';

    #[Locked]
    public string $panelSection = 'reports';

    #[Locked]
    public array $panelFilters = [];

    public function selectPanelSection(string $section): void
    {
        app(RepaymentPanelQuery::class)->query(auth()->user(), $section, $this->panelFilters);
        $this->panelSection = $section;
        $this->resetPage();
    }

    #[On('personnel-record-updated')]
    public function refreshRepaymentPanel(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table->filters([])->query(fn () => app(RepaymentPanelQuery::class)->query(auth()->user(), 'global', $this->panelFilters));
    }

    protected function getViewData(): array
    {
        return [...parent::getViewData(), 'panelOverview' => app(RepaymentPanelQuery::class)->overview(auth()->user(), $this->panelFilters)];
    }

    protected static string $resource = GlobalRecoveryResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getSubheading(): ?string
    {
        return 'Verifica depósitos y revisa apelaciones desde un solo panel. Los historiales siempre se conservan.';
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('filtrarReposiciones')->label('Filtros')->icon('heroicon-o-funnel')->color('gray')->modalHeading('Filtros de reposiciones')->modalWidth('2xl')
                ->modalDescription('Cajera y fechas se aplican a las tres pestañas. Medio y estado se aplican a reposiciones. El acumulado general siempre incluye a todo el personal.')
                ->fillForm(fn (): array => $this->panelFilters)->schema([Grid::make(2)->schema([
                    Select::make('user_id')->label('Cajera')->options(fn () => User::orderBy('name')->pluck('name', 'id'))->searchable()->placeholder('Todo el personal')->columnSpanFull(),
                    DatePicker::make('desde')->label('Desde'),
                    DatePicker::make('hasta')->label('Hasta')->afterOrEqual('desde'),
                    Select::make('method')->label('Medio')->options(['EFECTIVO' => 'Efectivo', 'DEPOSITO' => 'Depósito', 'QR' => 'Yape / QR', 'SUELDO' => 'Descuento salarial'])->placeholder('Todos'),
                    Select::make('status')->label('Estado de reposición')->options(['pending' => 'Por verificar', 'verified' => 'Verificada', 'rejected' => 'Rechazada'])->placeholder('Todos'),
                ])])->modalSubmitActionLabel('Aplicar filtros')->modalCancelActionLabel('Volver')
                ->action(function (array $data): void {
                    $this->panelFilters = app(RepaymentPanelQuery::class)->filters($data);
                    $this->resetTable();
                }),
            Action::make('limpiarFiltros')->label('Limpiar filtros')->icon('heroicon-o-x-mark')->color('gray')->visible(fn (): bool => collect($this->panelFilters)->filter(fn ($v) => filled($v))->isNotEmpty())->action(function (): void {
                $this->panelFilters = [];
                $this->resetTable();
            }),
            Action::make('configurarReposiciones')->label('Configuración')->icon('heroicon-o-cog-6-tooth')->modalHeading('Configuración de reposiciones de caja')->modalWidth('lg')->modalDescription('Faltantes activos desde su registro. Una apelación por caso. Anular o perdonar exige PIN. El destino de reposiciones es independiente de la cuenta de origen y de las reglas de liquidación de cada caja.')->fillForm(fn (): array => [...app(RepaymentSettingsQuery::class)->execute(auth()->user()), 'request_key' => (string) Str::uuid()])->schema([
                Select::make('destination_key')->label('Cuenta de comisiones para reposiciones')->options(fn (): array => app(RepaymentSettingsQuery::class)->execute(auth()->user())['options'])->required(),
                TextInput::make('pin')->label('PIN personal de Administración')->password()->revealable()->required()->maxLength(4),
                Hidden::make('request_key'),
            ])->modalSubmitActionLabel('Guardar configuración')->modalCancelActionLabel('Volver')->action(fn (array $data) => app(SaveRepaymentSettingsAction::class)->execute(auth()->user(), $data)),
            PersonnelTable::modal(Action::make('declarar'))->label('Registrar reposición global')->icon('heroicon-o-plus')->modalHeading('Nueva reposición global')->modalDescription('Esta declaración no modifica el saldo pendiente hasta verificar la recepción real.')->schema(PersonnelModal::fields(GlobalRecoveryForm::components(true)))
                ->action(fn (array $data) => app(DeclareGlobalRecoveryAction::class)->execute(auth()->user(), User::findOrFail($data['user_id']), GlobalRecoveryForm::data($data))),
        ];
    }
}
