<?php

namespace App\Domain\Incidencias\Actions;

use Illuminate\Support\Facades\Validator;

class CondoneSmallMonthlyShortagesAction
{
    /** Compatibility entry point: automatic forgiveness was removed by ADR057. */
    public function execute(string $month): void
    {
        Validator::make(['month' => $month], ['month' => ['required', 'date_format:Y-m']])->validate();
    }
}
