<?php

namespace App\Filament\Pages\ControlHorario;

use App\Domain\Comisiones\Queries\AdminCommissionsQuery;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\ControlHorario\Queries\PersonnelRecordQuery;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Filament\Resources\ControlHorario\LeaveRequests\Tables\LeaveRequestsTable;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\Tables\ScheduledWorkdaysTable;
use App\Filament\Resources\ControlHorario\TimeIncidents\Tables\TimeIncidentsTable;
use App\Filament\Resources\Incidencias\EconomicIncidents\Tables\EconomicIncidentsTable;
use App\Filament\Resources\Usuarios\Users\UserResource;
use App\Filament\Support\Comisiones\CommissionPaymentActions;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Widgets\Comisiones\AdminCommissionHistoryWidget;
use App\Filament\Widgets\Comisiones\CommissionPaymentsWidget;
use App\Support\Bolivianos;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;

class PersonnelRecord extends Page implements HasTable
{
    use HandlesPersonnelActionErrors;
    use InteractsWithTable;

    protected string $view = 'control-horario.personnel-record';

    protected static ?string $title = 'Expediente del personal';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public int $employeeId;

    #[Locked]
    public string $section = 'time';

    public static function canAccess(): bool
    {
        return CashierStatus::canAccess();
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        $employee = app(PersonnelRecordQuery::class)->employee(auth()->user(), $this->employeeId);

        return [
            Action::make('back')->label('Volver al personal')->url(CashierStatus::getUrl()),
            Action::make('profile')->label('Perfil y condiciones')->visible(UserResource::canEdit($employee))->url(UserResource::getUrl('edit', ['record' => $employee])),
        ];
    }

    public function mount(): void
    {
        $id = filter_var(request()->query('employee'), FILTER_VALIDATE_INT);
        abort_unless($id, 404);
        $this->employeeId = app(PersonnelRecordQuery::class)->employee(auth()->user(), $id)->id;
    }

    public function sections(): array
    {
        $sections = ['time' => 'Atrasos y faltas', 'money' => 'Faltantes', 'leave' => 'Permisos', 'days' => 'Jornadas', 'openings' => 'Control de aperturas'];
        if (app(AdminCommissionsQuery::class)->canView(auth()->user())) {
            $sections['commissions'] = 'Comisiones';
        }

        return $sections;
    }

    public function selectSection(string $section): void
    {
        app(PersonnelRecordQuery::class)->employee(auth()->user(), $this->employeeId);
        abort_unless(array_key_exists($section, $this->sections()), 404);
        $this->unmountAction();
        $this->section = $section;
        $this->resetTable();
        $this->resetTableSearch();
        $this->resetTableColumnSearches();
    }

    protected function getViewData(): array
    {
        return app(PersonnelRecordQuery::class)->summary(auth()->user(), $this->employeeId);
    }

    public function table(Table $table): Table
    {
        app(PersonnelRecordQuery::class)->employee(auth()->user(), $this->employeeId);
        $id = $this->employeeId;
        $table->queryStringIdentifier('personnelRecord')->persistFiltersInSession(false);

        $configured = match ($this->section) {
            'time' => TimeIncidentsTable::configure($table->query(TimeIncident::where('user_id', $id))),
            'money' => EconomicIncidentsTable::configure($table->query(EconomicIncident::where('user_id', $id)))->headerActions([]),
            'leave' => LeaveRequestsTable::configure($table->query(LeaveRequest::where('user_id', $id)))->headerActions([]),
            'days' => ScheduledWorkdaysTable::configure($table->query(ScheduledWorkday::where('user_id', $id)))->headerActions([]),
            'commissions' => $this->commissions($table),
            'openings' => $this->openings($table),
            default => abort(404),
        };

        return $configured;
    }

    private function commissions(Table $table): Table
    {
        $query = app(AdminCommissionsQuery::class)->cashiers(auth()->user())->where('users.id', $this->employeeId);

        return $table->heading('Comisiones y retiros registrados')->description('Registra únicamente pagos realmente entregados. Los atrasos no descuentan comisiones automáticamente.')
            ->query($query)->columns([
                TextColumn::make('total_cents')->label('Ganado')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::format((int) $state)),
                TextColumn::make('paid_cents')->label('Pagado')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::format((int) $state)),
                TextColumn::make('pending_cents')->label('Pendiente')->formatStateUsing(fn ($state) => 'Bs '.Bolivianos::format((int) $state)),
            ])->recordActions([
                CommissionPaymentActions::pay(),
                Action::make('payments')->label('Historial de pagos')->modalSubmitAction(false)->modalWidth('7xl')->schema(fn ($record) => [Livewire::make(CommissionPaymentsWidget::class, ['userId' => $record->id])->key('payments-'.$record->id)]),
                Action::make('sales')->label('Origen de comisiones')->modalSubmitAction(false)->modalWidth('7xl')->schema(fn ($record) => [Livewire::make(AdminCommissionHistoryWidget::class, ['userId' => $record->id])->key('sales-'.$record->id)]),
            ])->paginated(false);
    }

    private function openings(Table $table): Table
    {
        return $table->heading('Aperturas y verificación de horario')->description('Una apertura sin jornada enlazada requiere revisar la asignación y los controles vigentes en ese momento. No se generan cargos retroactivos desde esta vista.')
            ->query(app(PersonnelRecordQuery::class)->openings(auth()->user(), $this->employeeId))
            ->columns([
                TextColumn::make('occurred_at')->label('Apertura')->dateTime('d/m/Y H:i:s')->timezone('America/La_Paz'),
                TextColumn::make('family')->label('Caja'),
                TextColumn::make('status')->label('Procesamiento')->badge(),
                TextColumn::make('scheduled_workday_id')->label('Jornada')->placeholder('Sin jornada enlazada'),
                TextColumn::make('scheduled_start')->label('Inicio programado')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->placeholder('Revisar asignación'),
                TextColumn::make('result')->label('Resultado actual de jornada')->state(function (CashBoxScheduleEvent $record): string {
                    if (! $record->delay_status) {
                        return $record->scheduled_workday_id ? 'Sin incidencia de retraso enlazada' : 'Revisar jornada y control de la caja';
                    }
                    $calculation = json_decode($record->delay_calculation ?? '{}', true);

                    return $record->delay_status.' · '.$record->real_minutes.' min reales · '.$record->nominal_minutes.' ponderados · '.$record->pending_minutes.' pendientes · tolerancia '.($calculation['tolerance'] ?? '—').' min';
                })->wrap(),
                TextColumn::make('error_code')->label('Diagnóstico técnico')->placeholder('Sin error informado'),
            ])->defaultSort('cash_box_schedule_events.id', 'desc')->paginated([10, 25, 50]);
    }
}
