<?php

namespace App\Filament\Pages\ControlHorario;

use App\Domain\Configuraciones\Actions\SavePersonnelRulesAction;
use App\Domain\ControlHorario\Calculators\ScheduleSettingsPreview;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Domain\ControlHorario\Queries\ScheduleSettingsQuery;
use App\Filament\Support\ControlHorario\ScheduleSettingsActions;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class ScheduleSettings extends Page
{
    use HandlesPersonnelActionErrors;

    protected string $view = 'control_horario.settings';

    protected static ?string $title = 'Control de horario';

    protected static ?string $slug = 'control-horario/configuracion';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuraciones';

    protected static ?int $navigationSort = 30;

    public array $data = [];

    #[Locked]
    public int $versionId = 0;

    #[Locked]
    public array $pendingSettings = [];

    public static function canAccess(): bool
    {
        return (auth()->user()?->is_active ?? false) && (auth()->user()?->hasRole('administrador') ?? false) && (auth()->user()?->can('configuraciones.personal') ?? false);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        $this->reloadSettings();
    }

    public function reloadSettings(): void
    {
        unset($this->settingsState, $this->preview, $this->shiftSummary, $this->savedShiftSummary);
        $state = app(ScheduleSettingsQuery::class)->execute(auth()->user());
        $this->versionId = $state['version_id'];
        $this->form->fill([...$state['settings'], 'all_boxes' => $state['settings']['schedule_cash_box_ids'] === null, 'example_start' => '08:00', 'example_opening' => '08:20', 'example_end' => '14:00', 'example_closing' => '13:50']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->schema([
            Section::make('Activar o pausar el control')->icon('heroicon-o-power')
                ->description('Este interruptor general se aplica al guardar. Pausar no borra retrasos, créditos ni historial y permite seguir operando las cajas.')
                ->schema([Toggle::make('cash_box_controls_enabled')->label('Control de horario activo en Cajas')->live()
                    ->helperText('Activado: controla las cajas y turnos seleccionados que tengan jornada asignada. Desactivado: pausa nuevas evaluaciones de Cajas.')]),
            Section::make('1. Horarios de los turnos')->description('Configuración general: elige en qué turnos aplicar el control y define sus reglas. No registra jornadas ni selecciona cajeras.')->schema([
                Grid::make(2)->schema([
                    Section::make('Turno mañana')->icon('heroicon-o-sun')->columns(2)->schema([
                        Toggle::make('morning_control_enabled')->label('Aplicar control en mañana')->helperText('Activa las reglas de entrada, retraso y salida para futuras jornadas de mañana.')->live()->columnSpanFull(),
                        TimePicker::make('morning_start')->label('Inicio de mañana')->seconds(false)->format('H:i')->required()->live(),
                        TimePicker::make('morning_end')->label('Fin de mañana')->seconds(false)->format('H:i')->required()->live(),
                        TextInput::make('tolerance_minutes')->label('Tolerancia de mañana (minutos)')->integer()->minValue(0)->maxValue(1440)->required()->live(debounce: 400),
                        TextInput::make('morning_closing_minutes')->label('Permitir cierre antes (minutos)')->helperText('10 permite cerrar desde las 13:50 si el turno termina a las 14:00.')->integer()->minValue(0)->maxValue(1440)->required()->live(debounce: 400),
                    ]),
                    Section::make('Turno tarde')->icon('heroicon-o-moon')->columns(2)->schema([
                        Toggle::make('afternoon_control_enabled')->label('Aplicar control en tarde')->helperText('Activa las reglas de entrada, retraso y salida para futuras jornadas de tarde.')->live()->columnSpanFull(),
                        TimePicker::make('afternoon_start')->label('Inicio de tarde')->seconds(false)->format('H:i')->required()->live(),
                        TimePicker::make('afternoon_end')->label('Fin de tarde')->seconds(false)->format('H:i')->required()->live(),
                        TextInput::make('afternoon_tolerance_minutes')->label('Tolerancia de tarde (minutos)')->integer()->minValue(0)->maxValue(1440)->required()->live(debounce: 400),
                        TextInput::make('afternoon_closing_minutes')->label('Permitir cierre antes (minutos)')->integer()->minValue(0)->maxValue(1440)->required()->live(debounce: 400),
                    ]),
                ]),
            ]),
            Section::make('2. Cómo se calcula el retraso')->description('Se aplica después de la tolerancia. No hay minutos de gracia adicionales.')->columns(2)->schema([
                Select::make('delay_multiplier')->label('Multiplicar los minutos de retraso por')->options([1 => '×1 · Un minuto cuenta como uno', 2 => '×2 · Un minuto cuenta como dos', 3 => '×3 · Un minuto cuenta como tres'])->required()->live(),
                TextInput::make('multiplier_minutes')->label('Aplicar ese factor durante (minutos de retraso)')->integer()->minValue(1)->maxValue(1440)->helperText('Vacío: durante todo el retraso. Después del tramo indicado, el exceso cuenta ×1.')->live(debounce: 400),
                Toggle::make('automatic_credit')->label('Compensar con crédito trabajado disponible')->helperText('Solo crédito verificado; no se genera crédito por dejar una caja abierta.')->live()->columnSpanFull(),
            ]),
            Section::make('3. Activación y cajas')->description('Elige las cajas donde se aplicará el interruptor general.')->schema([
                Hidden::make('schedule_enabled'),
                Toggle::make('all_boxes')->label('Aplicar a todas las cajas')->live(),
                Select::make('schedule_cash_box_ids')->label('Cajas donde se aplicará')->multiple()->options(fn () => $this->settingsState['boxes'])->searchable()
                    ->visible(fn ($get) => ! $get('all_boxes'))->helperText('Selecciona las cajas. Sin selección no se controlará ninguna.'),
            ]),
            Section::make('Probar un ejemplo de retraso')->description('Simulación independiente; no cambia horarios ni registra asistencia.')->collapsible()->collapsed()->columns(4)->schema([
                TimePicker::make('example_start')->label('Inicio de ejemplo')->seconds(false)->format('H:i')->required()->live(),
                TimePicker::make('example_opening')->label('Apertura de ejemplo')->seconds(false)->format('H:i')->required()->live(),
                TimePicker::make('example_end')->label('Fin de ejemplo')->seconds(false)->format('H:i')->required()->live(),
                TimePicker::make('example_closing')->label('Cierre de ejemplo')->seconds(false)->format('H:i')->required()->live(),
            ]),
        ]);
    }

    #[Computed]
    public function shiftSummary(): array
    {
        try {
            app(ScheduleConfiguration::class)->validate($this->data);

            return app(ScheduleConfiguration::class)->summaries($this->data);
        } catch (ValidationException $exception) {
            return [];
        }
    }

    #[Computed]
    public function preview(): array
    {
        app(ScheduleSettingsQuery::class)->execute(auth()->user());

        return app(ScheduleSettingsPreview::class)->calculate($this->data);
    }

    #[Computed]
    public function settingsState(): array
    {
        return app(ScheduleSettingsQuery::class)->execute(auth()->user());
    }

    #[Computed]
    public function savedShiftSummary(): array
    {
        return app(ScheduleConfiguration::class)->summaries($this->settingsState['settings']);
    }

    public function configureControlAction(): Action
    {
        return ScheduleSettingsActions::configure('configureControl', 'control', 'Control general', 'Enciende o pausa el control horario de Cajas.', [
            'Activado: evalúa nuevas aperturas y comprueba el horario antes de cerrar, en las cajas y turnos incluidos.',
            'Pausado: puedes seguir operando; no se borran retrasos, créditos ni historial.',
            'Guardar no asigna jornadas ni registra llegadas anteriores. Cada cajera necesita su jornada por fecha.',
        ], [Toggle::make('cash_box_controls_enabled')->label('Control de horario activo en Cajas')->columnSpanFull()]);
    }

    public function configureMorningAction(): Action
    {
        return $this->configureTurn('morning', 'manana', 'Mañana');
    }

    public function configureAfternoonAction(): Action
    {
        return $this->configureTurn('afternoon', 'tarde', 'Tarde');
    }

    private function configureTurn(string $key, string $period, string $label): Action
    {
        $tolerance = $key === 'morning' ? 'tolerance_minutes' : 'afternoon_tolerance_minutes';

        return ScheduleSettingsActions::configure('configure'.ucfirst($key), $key, 'Turno '.$label, 'Horario, tolerancia y primera hora permitida de cierre.', [
            'Inicio y fin son la plantilla para nuevas jornadas; no reescriben las ya asignadas.',
            'La tolerancia permite abrir sin retraso hasta esos minutos después del inicio. Cierre antes permite salir ese número de minutos antes del fin.',
            'Desactivar este turno deja de aplicar su control horario, aunque el control general esté activo. No cambia el turno financiero ni la liquidación.',
        ], [
            Toggle::make($key.'_control_enabled')->label('Aplicar control en '.mb_strtolower($label))->columnSpanFull(),
            TimePicker::make($key.'_start')->label('Inicio')->seconds(false)->format('H:i')->required(),
            TimePicker::make($key.'_end')->label('Fin')->seconds(false)->format('H:i')->required(),
            TextInput::make($tolerance)->label('Tolerancia al abrir (min)')->integer()->minValue(0)->maxValue(1440)->required(),
            TextInput::make($key.'_closing_minutes')->label('Permitir cierre antes (min)')->integer()->minValue(0)->maxValue(1440)->required()->helperText('0: esperar hasta la hora de fin.'),
        ]);
    }

    public function configureDelayAction(): Action
    {
        return ScheduleSettingsActions::configure('configureDelay', 'delay', 'Retrasos y créditos', 'Cómo se acumula el tiempo después de la tolerancia.', [
            '×2 convierte 1 minuto de retraso en 2 acumulados. El tramo indica durante cuántos minutos de retraso se aplica; vacío abarca todo.',
            'Después del tramo, el exceso cuenta ×1. No se recalculan los retrasos que ya están registrados.',
            'Compensación automática: usa crédito de trabajo verificado para reducir retrasos. Desactivada: conserva el crédito para aplicación manual; no se pierde.',
        ], [
            Select::make('delay_multiplier')->label('Factor del retraso')->options([1 => '×1', 2 => '×2', 3 => '×3'])->required(),
            TextInput::make('multiplier_minutes')->label('Duración del factor (min)')->integer()->minValue(1)->maxValue(1440)->nullable()->helperText('Vacío: todo el retraso.'),
            Toggle::make('automatic_credit')->label('Compensar automáticamente con crédito verificado')->columnSpanFull(),
        ]);
    }

    public function configureBoxesAction(): Action
    {
        return ScheduleSettingsActions::configure('configureBoxes', 'boxes', 'Cajas incluidas', 'Limita dónde se aplica el control general.', [
            'Todas incluye las cajas actuales y las que crees después. Elegidas limita el control a tu selección.',
            'Si desactivas Todas y no eliges ninguna, no se controla ninguna caja. Las excluidas siguen operando con las reglas financieras habituales.',
            'Elegir una caja no activa el control general ni asigna una jornada a la cajera.',
        ], [
            Toggle::make('all_boxes')->label('Aplicar a todas las cajas')->live()->columnSpanFull(),
            Select::make('schedule_cash_box_ids')->label('Elegir cajas')->multiple()->searchable()->options(fn () => $this->settingsState['boxes'])
                ->visible(fn ($get) => ! $get('all_boxes'))->columnSpanFull(),
        ]);
    }

    public function simulateScheduleAction(): Action
    {
        return ScheduleSettingsActions::simulate([
            TimePicker::make('example_start')->label('Inicio del ejemplo')->seconds(false)->format('H:i')->required()->live(),
            TimePicker::make('example_opening')->label('Apertura del ejemplo')->seconds(false)->format('H:i')->required()->live(),
            TimePicker::make('example_end')->label('Fin del ejemplo')->seconds(false)->format('H:i')->required()->live(),
            TimePicker::make('example_closing')->label('Cierre del ejemplo')->seconds(false)->format('H:i')->required()->live(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function saveSettingsAction(): Action
    {
        return Action::make('saveSettings')->label('Guardar configuración')->icon('heroicon-o-check')->modalHeading('Guardar reglas de horario')->modalWidth('lg')
            ->modalDescription('Se guardará una nueva versión para futuras jornadas. La activación o pausa se aplicará al guardar. No se recalcula el historial ni se crean jornadas.')
            ->fillForm(function (): array {
                $state = $this->form->getState();
                try {
                    app(ScheduleConfiguration::class)->validate($state);
                } catch (ValidationException $exception) {
                    $messages = [];
                    foreach ($exception->errors() as $field => $errors) {
                        $messages['data.'.$field] = $errors;
                    }
                    throw ValidationException::withMessages($messages);
                }
                $this->pendingSettings = Arr::only($state, ['tolerance_minutes', 'delay_multiplier', 'multiplier_minutes', 'automatic_credit', ...ScheduleConfiguration::FIELDS]);
                $this->pendingSettings['schedule_cash_box_ids'] = ($state['all_boxes'] ?? false) ? null : ($state['schedule_cash_box_ids'] ?? []);

                return ['reason' => '', 'pin' => '', 'request_key' => (string) Str::uuid()];
            })->schema([
                Textarea::make('reason')->label('Motivo del cambio')->required()->minLength(3)->maxLength(2000),
                TextInput::make('pin')->label('Tu PIN de Administración')->password()->revealable()->required()->length(4),
                Hidden::make('request_key'),
            ])->modalSubmitActionLabel('Guardar reglas')->modalCancelActionLabel('Volver sin guardar')
            ->action(function (array $data): void {
                $version = app(SavePersonnelRulesAction::class)->execute(auth()->user(), [...$this->pendingSettings, ...Arr::only($data, ['reason', 'pin', 'request_key']), 'expected_version_id' => $this->versionId]);
                $this->versionId = $version->id;
                $this->pendingSettings = [];
                unset($this->settingsState,$this->preview,$this->shiftSummary,$this->savedShiftSummary);
                Notification::make()->success()->title('Configuración de horario guardada')->send();
            });
    }
}
