<?php

namespace App\Filament\Pages\Reportes;

class LoanReport extends ModuleReport
{
    protected const MODULE = 'LOANS';

    protected static ?string $title = 'Reporte de Préstamos';

    protected static ?string $navigationLabel = 'Préstamos';

    protected static ?string $slug = 'reportes/prestamos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 4;
}
