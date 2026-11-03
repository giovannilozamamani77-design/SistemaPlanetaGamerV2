<?php

namespace App\Filament\Pages\ControlHorario;

use App\Domain\ControlHorario\Actions\RegisterAttendanceAction;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use SensitiveParameter;

class MiAsistencia extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'control_horario.mi-asistencia';

    protected static ?string $title = 'Mi asistencia';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Mi perfil';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('controlhorario.ver_propios') ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('registrarLlegada')->label('Registrar PIN')
                ->visible(fn (): bool => auth()->user()->can('controlhorario.asistencia.registrar'))
                ->schema([TextInput::make('pin')->label('PIN personal')->password()->required()])
                ->action(function (#[SensitiveParameter] array $data): void {
                    $attendance = app(RegisterAttendanceAction::class)->execute(auth()->user(), $data['pin']);
                    Notification::make()->title($attendance ? 'Asistencia registrada' : 'PIN validado; no hay jornada vigente programada para hoy')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->query(ScheduledWorkday::where('user_id', auth()->id())->with('attendance'))
            ->columns([
                TextColumn::make('work_date')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('period')->label('Jornada')->formatStateUsing(fn (string $state): string => $state === 'tarde' ? 'Tarde' : 'Mañana'),
                TextColumn::make('starts_at')->label('Inicio')->dateTime('H:i')->timezone('America/La_Paz'),
                TextColumn::make('ends_at')->label('Fin')->dateTime('H:i')->timezone('America/La_Paz'),
                TextColumn::make('attendance.first_pin_at')->label('PIN registrado')->dateTime('d/m/Y H:i:s')->timezone('America/La_Paz')->placeholder('Sin registro'),
                TextColumn::make('attendance.status')->label('Estado')->placeholder('Sin registro')->badge(),
            ])->defaultSort('starts_at', 'desc');
    }
}
