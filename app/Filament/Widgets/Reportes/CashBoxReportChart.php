<?php

namespace App\Filament\Widgets\Reportes;

use Livewire\Attributes\Locked;

class CashBoxReportChart extends ModuleReportChart
{
    #[Locked]
    public string $label = '';

    public function getHeading(): string
    {
        return $this->label;
    }
}
