<?php

namespace App\Filament\Pages\ControlHorario;

use App\Domain\ControlHorario\Actions\SaveRecurringWorkShiftAction;
use App\Domain\ControlHorario\Models\RecurringWorkShift;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\ScheduledWorkdayResource;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Personal\PersonnelTable;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class AssignedShifts extends Page implements HasTable
{
    use HandlesPersonnelActionErrors;
    use InteractsWithTable;

    protected string $view = 'control_horario.assigned-shifts';

    protected static ?string $title = 'Turnos asignados';

    protected static string|\UnitEnum|null $navigationGroup = 'Personal';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 9;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->is_active && $user->hasRole('administrador') && $user->can('controlhorario.ver_todos') && $user->can('controlhorario.programar');
    }

    public function getSubheading(): ?string
    {
        return 'Asigna una vez el turno habitual. Se aplica de lunes a sábado; el domingo queda fuera del control habitual.';
    }

    protected function getHeaderActions(): array
    {
        return [PersonnelTable::modal(Action::make('assignShift'))->label('Asignar turno')->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Asignar turno habitual')->modalDescription('Se repetirá de lunes a sábado hasta que lo desactives. Los horarios se toman de Configuraciones → Control de horario. No genera faltas de fechas anteriores.')
            ->schema([Section::make('Cajera y turno')->columns(2)->schema([
                Select::make('user_id')->label('Cajera')->options(fn () => User::role('cajera')->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                Select::make('period')->label('Turno habitual')->options(['manana' => 'Mañana', 'tarde' => 'Tarde'])->required()->helperText('Para ambos turnos, asigna mañana y tarde por separado.'),
                Hidden::make('enabled')->default(true), Hidden::make('expected_version_id')->default(0), Hidden::make('request_key')->default(fn () => (string) Str::uuid()),
            ])])->action(function (array $data): void {
                app(SaveRecurringWorkShiftAction::class)->execute(auth()->user(), $data);
                Notification::make()->title('Turno habitual asignado')->success()->send();
            }),
            Action::make('coverages')->label('Coberturas por día')->icon('heroicon-o-user-group')->color('gray')->url(fn () => ShiftCoverages::getUrl()),
            Action::make('dailyHistory')->label('Historial diario y excepciones')->icon(Heroicon::OutlinedClock)->color('gray')->url(fn () => ScheduledWorkdayResource::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->query(RecurringWorkShift::current()->with('user'))->heading('Horarios habituales')->description('Sin fechas diarias: una asignación se repite de lunes a sábado.')
            ->columns([
                TextColumn::make('user.name')->label('Cajera')->searchable()->weight('semibold'),
                TextColumn::make('period')->label('Turno')->formatStateUsing(fn (string $state) => $state === 'tarde' ? 'Tarde' : 'Mañana')->badge(),
                TextColumn::make('enabled')->label('Estado')->formatStateUsing(fn (bool $state) => $state ? 'Activo' : 'Desactivado')->badge()->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextColumn::make('revision')->label('Versión'),
                TextColumn::make('created_at')->label('Último cambio')->dateTime('d/m/Y H:i')->timezone('America/La_Paz'),
            ])->recordActions([
                PersonnelTable::modal(Action::make('toggleShift'))->label(fn (RecurringWorkShift $record) => $record->enabled ? 'Desactivar' : 'Activar')->icon(Heroicon::OutlinedPower)
                    ->color(fn (RecurringWorkShift $record) => $record->enabled ? 'warning' : 'success')
                    ->modalHeading('Cambiar turno habitual')->modalDescription('Afecta la generación de los próximos registros diarios. Conserva los turnos ya registrados, asistencias, retrasos y todo el historial.')
                    ->fillForm(fn (RecurringWorkShift $record) => ['user_id' => $record->user_id, 'period' => $record->period, 'enabled' => ! $record->enabled, 'expected_version_id' => $record->id, 'request_key' => (string) Str::uuid()])
                    ->schema([Hidden::make('user_id'), Hidden::make('period'), Hidden::make('enabled'), Hidden::make('expected_version_id'), Hidden::make('request_key')])
                    ->action(fn (array $data) => app(SaveRecurringWorkShiftAction::class)->execute(auth()->user(), $data)),
            ])->defaultSort('id', 'desc')->paginated([5, 10, 25])->defaultPaginationPageOption(5)
            ->emptyStateHeading('Asigna el turno habitual de cada cajera')->emptyStateDescription('Solo se hace una vez. No se han convertido asignaciones antiguas automáticamente.');
    }
}
