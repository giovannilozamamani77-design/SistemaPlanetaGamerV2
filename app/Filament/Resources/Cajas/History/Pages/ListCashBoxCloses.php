<?php

namespace App\Filament\Resources\Cajas\History\Pages;

use App\Filament\Resources\Cajas\History\CashBoxCloseHistoryResource;
use App\Filament\Support\Personal\HandlesPersonnelActionErrors;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListCashBoxCloses extends ListRecords
{
    use HandlesPersonnelActionErrors;

    protected static string $resource = CashBoxCloseHistoryResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }
}
