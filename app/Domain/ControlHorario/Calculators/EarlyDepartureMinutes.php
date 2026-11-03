<?php

namespace App\Domain\ControlHorario\Calculators;

use Carbon\CarbonImmutable;

class EarlyDepartureMinutes
{
    public function calculate(CarbonImmutable $end, CarbonImmutable $instant, int $normalWindow): int
    {
        if ($instant >= $end->subMinutes($normalWindow)) {
            return 0;
        }

        return intdiv($end->getTimestamp() - $instant->getTimestamp() + 59, 60);
    }
}
