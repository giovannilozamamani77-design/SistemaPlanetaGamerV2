<?php

namespace App\Filament\Support\ControlHorario;

use App\Domain\ControlHorario\Data\TimeReasonCodes;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;

class TimeReasonForm
{
    public static function fields(): array
    {
        return [Select::make('code')->label('Motivo')->options(TimeReasonCodes::options())->required(),
            Textarea::make('reason')->label('Explica qué ocurrió')->helperText('Indica la hora y lo sucedido. Administración revisará el caso; enviarlo no descuenta tiempo automáticamente.')->rows(3)->required()->maxLength(2000)];
    }
}
