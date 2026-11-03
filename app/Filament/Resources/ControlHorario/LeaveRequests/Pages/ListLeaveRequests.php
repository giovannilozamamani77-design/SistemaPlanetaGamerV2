<?php

namespace App\Filament\Resources\ControlHorario\LeaveRequests\Pages;

use App\Filament\Resources\ControlHorario\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use App\Filament\Widgets\Personal\AdministrativePersonnelOverview;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListLeaveRequests extends ListRecords
{
    use HandlesPersonnelActionErrors;

    protected static string $resource = LeaveRequestResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getSubheading(): ?string
    {
        return 'Solicitudes de permisos y uso del día libre. Revisa el motivo antes de aprobar o rechazar.';
    }

    protected function getHeaderWidgets(): array
    {
        return [AdministrativePersonnelOverview::make(['section' => 'permisos'])];
    }
}
