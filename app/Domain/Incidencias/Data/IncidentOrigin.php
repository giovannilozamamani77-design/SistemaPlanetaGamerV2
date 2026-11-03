<?php

namespace App\Domain\Incidencias\Data;

use App\Domain\Incidencias\Models\EconomicIncident;

final class IncidentOrigin
{
    public static function label(EconomicIncident $record): string
    {
        if ($record->source_module === 'tarjetas') {
            if (preg_match('/\Acard-open:[0-9]+\z/', $record->source_reference)) {
                return 'Detectado en apertura';
            }
            if (preg_match('/\Acard-close:[0-9]+\z/', $record->source_reference)) {
                return 'Cierre de turno';
            }

            return 'Tarjetas';
        }
        if ($record->source_module === 'cajas') {
            if (preg_match('/\Areceipt:[0-9]+\z/', $record->source_reference)) {
                return 'Detectado en apertura';
            }
            if (preg_match('/\Aclose:[0-9]+\z/', $record->source_reference)) {
                return 'Cierre de turno';
            }

            return 'Origen de caja no detallado';
        }

        return $record->source_module === 'manual' ? 'Registro manual' : 'Otro origen';
    }
}
