<?php

namespace App\Filament\Resources\Incidencias\EconomicIncidents\Pages;

use App\Filament\Resources\Incidencias\EconomicIncidents\EconomicIncidentResource;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Widgets\Personal\AdministrativePersonnelOverview;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListEconomicIncidents extends ListRecords
{
    use HandlesPersonnelActionErrors;

    protected static string $resource = EconomicIncidentResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getSubheading(): ?string
    {
        return 'Distingue el importe original de la deuda pendiente. Para pagos acumulados utiliza Reposiciones de caja → Reposiciones.';
    }

    protected function getHeaderWidgets(): array
    {
        return [AdministrativePersonnelOverview::make(['section' => 'faltantes'])];
    }
}
