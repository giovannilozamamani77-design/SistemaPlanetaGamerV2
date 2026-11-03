<?php

namespace App\Domain\ControlHorario\Calculators;

use Carbon\CarbonImmutable;

class DelayCalculator
{
    /** @return array<string,int|null> */
    public function calculate(CarbonImmutable $start, CarbonImmutable $opening, int $tolerance = 15, int $multiplier = 2, ?int $window = 30): array
    {
        $seconds = max(0, $opening->getTimestamp() - $start->getTimestamp() - $tolerance * 60);
        $real = intdiv($seconds + 59, 60);
        $first = $window === null ? $real : min($window, $real);

        return ['tolerance' => $tolerance, 'grace' => 0, 'observed' => $real, 'forgiven' => 0, 'real' => $real, 'multiplier' => $multiplier, 'window' => $window, 'weighted' => $multiplier * $first + ($real - $first)];
    }
}
