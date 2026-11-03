<?php

namespace App\Filament\Widgets\Usuarios;

use App\Domain\ControlHorario\Actions\RegisterAttendanceAction;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Gate;
use SensitiveParameter;

class OwnAttendanceWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return self::configureTable($table)->headerActions([self::registerPinAction()]);
    }

    public static function configureTable(Table $table): Table
    {
        Gate::authorize('controlhorario.ver_propios');

        return $table->paginated([5])->defaultPaginationPageOption(5)->emptyStateHeading('Todavía no tienes jornadas programadas')->heading('Mi asistencia')->query(ScheduledWorkday::where('user_id', auth()->id())->with('attendance'))->columns([
            TextColumn::make('work_date')->label('Fecha')->date('d/m/Y')->sortable(),
            TextColumn::make('period')->label('Jornada')->formatStateUsing(fn (string $state): string => $state === 'tarde' ? 'Tarde' : 'Mañana'),
            TextColumn::make('starts_at')->label('Inicio')->dateTime('H:i')->timezone('America/La_Paz'),
            TextColumn::make('ends_at')->label('Fin')->dateTime('H:i')->timezone('America/La_Paz'),
            TextColumn::make('attendance.first_pin_at')->label('Llegada por PIN')->dateTime('d/m/Y H:i')->timezone('America/La_Paz')->placeholder('Sin registro'),
            TextColumn::make('attendance.status')->label('Estado')->placeholder('Sin registro')->badge(),
        ])->defaultSort('starts_at', 'desc');
    }

    public static function registerPinAction(): Action
    {
        return Action::make('registrarLlegada')->label('Registrar PIN')->visible(fn (): bool => auth()->user()->can('controlhorario.asistencia.registrar'))
            ->schema([TextInput::make('pin')->label('PIN personal')->password()->required()])
            ->action(function (#[SensitiveParameter] array $data): void {
                $result = app(RegisterAttendanceAction::class)->execute(auth()->user(), $data['pin']);
                Notification::make()->title($result ? 'Asistencia registrada' : 'PIN validado; no hay jornada vigente programada para hoy')->success()->send();
            });
    }
}
