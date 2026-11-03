<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\MonthlyLeave;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class PrepareMonthlyTimeDiscountsAction
{
    /** Calendar leave expiry only; salary resolutions are explicitly administrative. */
    public function execute(string $month): void
    {
        if (! preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $month)) {
            throw ValidationException::withMessages(['month' => 'Mes inválido.']);
        }
        if (CarbonImmutable::parse($month.'-01', 'America/La_Paz')->addMonth()->utc() > now()) {
            throw ValidationException::withMessages(['month' => 'El mes aún no terminó.']);
        }
        MonthlyLeave::where('month', '<=', $month)->where('status', 'DISPONIBLE')->update(['status' => 'VENCIDO']);
    }
}
