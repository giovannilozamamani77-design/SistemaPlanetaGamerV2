<?php

namespace App\Filament\Pages\Tarjetas;

use App\Filament\Support\Tarjetas\CardStoreCountPage;

class CardStoreClosing extends CardStoreCountPage
{
    protected static string $countKind = 'CLOSE';

    protected static ?string $title = 'Cerrar Tarjetas';

    protected static ?string $slug = 'tarjetas/cerrar';

    public function confirmClosing(): void
    {
        $this->confirmCount();
    }

    public function cancelClosing(): void
    {
        $this->cancelCount();
    }
}
