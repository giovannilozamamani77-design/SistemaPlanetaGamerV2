<?php

namespace App\Filament\Resources\Cajas\Workflow\Pages;

use App\Filament\Resources\Cajas\Workflow\CashBoxCorrectionResource;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListCashBoxCorrectionRecords extends ListRecords
{
    use HandlesPersonnelActionErrors;

    protected static string $resource = CashBoxCorrectionResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }
}
