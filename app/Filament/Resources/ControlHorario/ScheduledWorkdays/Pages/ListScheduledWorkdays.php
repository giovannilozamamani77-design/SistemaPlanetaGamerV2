<?php

namespace App\Filament\Resources\ControlHorario\ScheduledWorkdays\Pages;

use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\ScheduledWorkdayResource;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Support\Personal\PersonnelTable;
use App\Filament\Widgets\Personal\AdministrativePersonnelOverview;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListScheduledWorkdays extends ListRecords
{
    use HandlesPersonnelActionErrors;

    protected static string $resource = ScheduledWorkdayResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getSubheading(): ?string
    {
        return 'Registros diarios del turno habitual y excepciones de un solo día. Para asignar un horario permanente usa Turnos asignados.';
    }

    protected function getHeaderWidgets(): array
    {
        return [AdministrativePersonnelOverview::make(['section' => 'jornadas'])];
    }

    protected function getHeaderActions(): array
    {
        return [
            PersonnelTable::modal(CreateAction::make())->label('Añadir excepción de un día')->modalHeading('Turno excepcional de un solo día')
                ->modalDescription('La fecha indica el día de trabajo. Esta asignación no es recurrente. El PIN marca la llegada y al confirmar la apertura se evalúa el retraso.')
                ->using(fn (array $data): ScheduledWorkday => app(SaveScheduledWorkdayAction::class)->execute(auth()->user(), $data)),
        ];
    }
}
