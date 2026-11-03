<?php

namespace App\Filament\Resources\Cajas\EarlyRequests\Pages;

use App\Filament\Resources\Cajas\EarlyRequests\CashBoxEarlyRequestResource;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListCashBoxEarlyRequests extends ListRecords
{
    use HandlesPersonnelActionErrors;

    protected static string $resource = CashBoxEarlyRequestResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }
}
