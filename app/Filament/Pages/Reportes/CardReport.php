<?php

namespace App\Filament\Pages\Reportes;

class CardReport extends ModuleReport
{
    protected const MODULE = 'CARDS';

    protected static ?string $title = 'Reporte de Tarjetas';

    protected static ?string $navigationLabel = 'Tarjetas';

    protected static ?string $slug = 'reportes/tarjetas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 3;
}
