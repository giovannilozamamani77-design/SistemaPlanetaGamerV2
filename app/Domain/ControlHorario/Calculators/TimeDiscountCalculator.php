<?php

namespace App\Domain\ControlHorario\Calculators;

use InvalidArgumentException;

class TimeDiscountCalculator
{
    public function cents(int $minutes, int $rateCents = 5000, int $equivalentMinutes = 360): int
    {
        if ($minutes < 0 || $rateCents < 0 || $equivalentMinutes <= 0) {
            throw new InvalidArgumentException('Parámetros inválidos.');
        }

        return intdiv($minutes * $rateCents + intdiv($equivalentMinutes, 2), $equivalentMinutes);
    }
}
