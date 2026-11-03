<?php

namespace App\Domain\Accesorios\Calculators;

use App\Domain\Accesorios\Models\PhoneUnit;
use Carbon\CarbonImmutable;

class PhoneWarrantyCalculator
{
    public const TERMS_VERSION = 'C264-1';

    public const TERMS = 'Cubre fallas internas de hardware o software dentro del plazo. No cubre golpes, pantalla rota, humedad, raspones, mal uso ni reparaciones externas. En equipos usados se excluyen los defectos conocidos informados al vender. La atención requiere el mismo equipo e IMEI del recibo. Una garantía aceptada se resuelve primero con reparación, luego reemplazo y, si no es posible, devolución, conservando el registro original.';

    public function calculate(PhoneUnit $phone, CarbonImmutable $soldAt): array
    {
        $start = $soldAt->timezone('America/La_Paz')->startOfDay();
        $months = $phone->condition === 'NEW' ? 3 : 2;

        return [
            'starts_on' => $start->toDateString(),
            'expires_on' => $start->addMonthsNoOverflow($months)->toDateString(),
            'months' => $months,
            'terms_version' => self::TERMS_VERSION,
            'terms' => self::TERMS,
        ];
    }
}
