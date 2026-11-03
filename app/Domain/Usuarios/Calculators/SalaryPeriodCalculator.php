<?php

namespace App\Domain\Usuarios\Calculators;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class SalaryPeriodCalculator
{
    /** @return array{start:string,end:string}|null */
    public function forUser(User $user, CarbonInterface $at): ?array
    {
        $payDay = $user->salary_pay_day;
        if (! $payDay) {
            return null;
        }
        $date = CarbonImmutable::instance($at)->setTimezone('America/La_Paz')->startOfDay();
        $month = $date->startOfMonth();
        $cut = $month->day(min((int) $payDay, $month->daysInMonth));
        if ($date < $cut) {
            $month = $month->subMonth();
            $cut = $month->day(min((int) $payDay, $month->daysInMonth));
        }
        $next = $month->addMonth();
        $end = $next->day(min((int) $payDay, $next->daysInMonth))->subDay();

        return ['start' => $cut->toDateString(), 'end' => $end->toDateString()];
    }
}
