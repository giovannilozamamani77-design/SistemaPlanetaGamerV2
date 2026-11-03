<?php

namespace App\Filament\Pages\Reportes;

class AccessoryReport extends ModuleReport
{
    protected const MODULE = 'ACCESSORIES';

    protected static ?string $title = 'Reporte de Accesorios y teléfonos';

    protected static ?string $navigationLabel = 'Accesorios y teléfonos';

    protected static ?string $slug = 'reportes/accesorios';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 5;
}
