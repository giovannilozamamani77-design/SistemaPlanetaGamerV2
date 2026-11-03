<?php

namespace App\Domain\Reportes\Calculators;

use App\Support\Bolivianos;

class DailyComparisonCalculator
{
    public function compare(int $currentCents, int $previousCents): array
    {
        $difference = $currentCents - $previousCents;
        $direction = $difference > 0 ? 'up' : ($difference < 0 ? 'down' : 'equal');

        return ['current_cents' => $currentCents, 'previous_cents' => $previousCents, 'difference_cents' => $difference, 'direction' => $direction,
            'label' => match ($direction) {
                'up' => 'Subió Bs '.Bolivianos::formatCompact($difference),
                'down' => 'Bajó Bs '.Bolivianos::formatCompact(abs($difference)),
                default => 'Sin cambios',
            }];
    }
}
