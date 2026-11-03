<?php

namespace App\Filament\Pages\Reportes;

class CashBoxReport extends ModuleReport
{
    protected const MODULE = 'BOXES';

    protected static ?string $title = 'Reporte de Cajas';

    protected static ?string $navigationLabel = 'Cajas';

    protected static ?string $slug = 'reportes/cajas';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 2;
}
