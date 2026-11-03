<?php

namespace App\Filament\Resources\ControlHorario\TimeIncidents\Pages;

use App\Filament\Resources\ControlHorario\TimeIncidents\TimeIncidentResource;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Widgets\Personal\AdministrativePersonnelOverview;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListTimeIncidents extends ListRecords
{
    use HandlesPersonnelActionErrors;

    protected static string $resource = TimeIncidentResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getSubheading(): ?string
    {
        return 'Revisa retrasos y ausencias de todas las cajeras. Tú decides cómo resolver cada caso; el sistema conserva la constancia.';
    }

    protected function getHeaderWidgets(): array
    {
        return [AdministrativePersonnelOverview::make(['section' => 'retrasos'])];
    }
}
