<?php

namespace App\Filament\Pages\Tarjetas;

use App\Filament\Support\Tarjetas\CardStoreCountPage;

class CardStoreOpening extends CardStoreCountPage
{
    protected static string $countKind = 'OPEN';

    protected static ?string $title = 'Abrir Tarjetas';

    protected static ?string $slug = 'tarjetas/abrir';

    public function confirmOpening(): void
    {
        $this->confirmCount();
    }

    public function cancelOpening(): void
    {
        $this->cancelCount();
    }
}
