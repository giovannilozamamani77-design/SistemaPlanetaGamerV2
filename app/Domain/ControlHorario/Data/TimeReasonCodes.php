<?php

namespace App\Domain\ControlHorario\Data;

class TimeReasonCodes
{
    public static function options(): array
    {
        return ['administracion_tardia' => 'El administrador llegó tarde', 'sistema' => 'Caída o error del sistema', 'conteo' => 'Conteo o corrección de saldos', 'otros' => 'Otro motivo'];
    }

    public static function valid(string $code): bool
    {
        return array_key_exists($code, self::options()) || in_array($code, ['cierre_anterior', 'personal', 'revision'], true);
    }

    public static function justifiable(string $code): bool
    {
        return in_array($code, ['administracion_tardia', 'cierre_anterior', 'sistema'], true);
    }

    public static function label(?string $code): string
    {
        return self::options()[$code ?? ''] ?? (['cierre_anterior' => 'Demora del cierre anterior', 'personal' => 'Motivo personal', 'revision' => 'Solicitar revisión'][$code ?? ''] ?? 'Sin motivo registrado');
    }
}
