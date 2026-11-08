<?php

namespace App\Filament\Resources\ControlHorario\ScheduledWorkdays\Schemas;

use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ScheduledWorkdayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([Section::make('Turno excepcional de un solo día')->description('Solo para excepciones. El turno habitual de lunes a sábado se configura una vez en Personal → Turnos asignados.')->columns(['default' => 1, 'md' => 2])->columnSpanFull()->schema([
            Select::make('user_id')->label('Cajera o encargada')->helperText('Persona que debe trabajar este turno.')->required()->searchable()->options(fn (): array => User::role(['cajera', 'encargado'])->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
            DatePicker::make('work_date')->label('Fecha del turno')->helperText('Día en que debe trabajar, no la fecha en que llenas este formulario. Ejemplo: 22/10/2026 para trabajar el 22 de octubre.')->required(),
            Select::make('period')->label('Turno')->helperText('Mañana o tarde. Puedes asignar ambos por separado para la misma fecha.')->options(['manana' => 'Mañana', 'tarde' => 'Tarde'])->required()->live()
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $shift = app(ScheduleConfiguration::class)->shifts(app(PersonnelRules::class)->current())[$state ?? 'manana'];
                    $set('starts_time', $shift['start']);
                    $set('ends_time', $shift['end']);
                }),
            TimePicker::make('starts_time')->label('Hora de entrada')->helperText('Se completa con la configuración de horario; puedes ajustarla para este turno.')->seconds(false)->required(),
            TimePicker::make('ends_time')->label('Hora de salida')->helperText('Hora prevista de finalización en Bolivia.')->seconds(false)->required(),
        ])->columnSpanFull()]);
    }
}
