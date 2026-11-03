<?php

namespace App\Filament\Support\Personal;

class PersonnelLabels
{
    public const STATES = [
        'PRESENTE' => 'Asistencia registrada', 'PRESENTE_SIN_APERTURA' => 'Llegada por PIN',
        'PENDIENTE_MOTIVO' => 'Esperando explicación', 'PENDIENTE_APROBACION' => 'Por revisar',
        'EN_REVISION' => 'En revisión', 'FALTA_PENDIENTE' => 'Ausencia por revisar',
        'SIN_RETRASO' => 'Sin retraso', 'CONFIRMADA' => 'Confirmada', 'JUSTIFICADA' => 'Justificada',
        'INVALIDADA' => 'Invalidada por error', 'PERDONADA' => 'Perdonada', 'PERMISO_AUTORIZADO' => 'Con permiso',
        'PENDIENTE' => 'Pendiente', 'APROBADA' => 'Aprobada', 'RECHAZADA' => 'Rechazada', 'REVERTIDA' => 'Revertida',
        'DETECTADA' => 'Por revisar', 'PAGO_PARCIAL' => 'Pago parcial', 'DESCUENTO_PROGRAMADO' => 'Descuento pendiente',
        'REPUESTA' => 'Saldada', 'ANULADA' => 'Anulada por error', 'CONDONADA' => 'Perdonada',
        'CONDONADA_AUTOMATICA_MENOR' => 'Condonación histórica', 'CARGO_BANCARIO' => 'Cargo bancario verificado',
        'RETRASO' => 'Retraso', 'FALTA' => 'Ausencia de jornada', 'FALTA_PARCIAL' => 'Salida anticipada',
        'AUSENCIA_FUTURA' => 'Permiso para jornada futura', 'COMPENSAR_RETRASOS' => 'Día libre por retrasos',
        'COMPENSAR_FALTA' => 'Día libre por ausencia', 'EFECTIVO' => 'Efectivo', 'DEPOSITO' => 'Depósito',
        'QR' => 'QR', 'SUELDO' => 'Descuento salarial', 'CAJERA' => 'Diferencia por revisar',
    ];

    public static function text(?string $state): string
    {
        return self::STATES[$state ?? ''] ?? ($state ?: 'Sin registro');
    }

    public static function color(?string $state): string
    {
        return match ($state) {
            'CONFIRMADA', 'PAGO_PARCIAL', 'FALTA_PENDIENTE', 'RECHAZADA' => 'danger',
            'PENDIENTE', 'DETECTADA', 'EN_REVISION', 'PENDIENTE_MOTIVO', 'PENDIENTE_APROBACION', 'DESCUENTO_PROGRAMADO' => 'warning',
            'PRESENTE', 'PRESENTE_SIN_APERTURA', 'APROBADA', 'REPUESTA', 'SIN_RETRASO', 'JUSTIFICADA', 'PERMISO_AUTORIZADO' => 'success',
            'PERDONADA', 'CONDONADA', 'CARGO_BANCARIO' => 'info',
            default => 'gray',
        };
    }

    public static function options(array $states): array
    {
        return array_combine($states, array_map(self::text(...), $states));
    }
}
