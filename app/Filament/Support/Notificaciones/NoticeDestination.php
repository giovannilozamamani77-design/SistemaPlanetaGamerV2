<?php

namespace App\Filament\Support\Notificaciones;

use App\Domain\Notificaciones\Models\PanelNotice;
use App\Filament\Pages\Cajas\CashBoxOperations;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use App\Filament\Resources\Cajas\History\CashBoxCloseHistoryResource;
use App\Filament\Resources\Cajas\Workflow\CashBoxCorrectionResource;
use App\Filament\Resources\ControlHorario\TimeIncidents\TimeIncidentResource;
use App\Filament\Resources\Incidencias\EconomicIncidents\EconomicIncidentResource;
use App\Filament\Resources\Incidencias\GlobalRecoveries\GlobalRecoveryResource;
use LogicException;

final class NoticeDestination
{
    public static function url(PanelNotice $notice): string
    {
        return match ($notice->target) {
            'cierres' => CashBoxCloseHistoryResource::getUrl(panel: 'inicio'),
            'reposiciones' => GlobalRecoveryResource::getUrl(panel: 'inicio'),
            'faltantes' => EconomicIncidentResource::getUrl(panel: 'inicio'),
            'correcciones' => $notice->category === 'correction-response'
                ? CashBoxOperations::getUrl(panel: 'inicio')
                : CashBoxCorrectionResource::getUrl(panel: 'inicio'),
            'horario' => TimeIncidentResource::getUrl(panel: 'inicio'),
            'perfil-comisiones' => EditOwnProfile::getUrl(panel: 'inicio').'?profileTab=profile-commercial-commissions',
            'perfil-horario' => EditOwnProfile::getUrl(panel: 'inicio').'?profileTab=profile-time-delays',
            'perfil' => EditOwnProfile::getUrl(panel: 'inicio').'?section=faltantes',
            default => throw new LogicException('Destino interno no reconocido.'),
        };
    }
}
