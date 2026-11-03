<?php

namespace App\Filament\Resources\Cajas\Types\Pages;

use App\Domain\Cajas\Actions\SaveCashBoxTypeAction;
use App\Domain\Cajas\Models\CashBoxType;
use App\Filament\Resources\Cajas\Types\CashBoxTypeResource;
use App\Filament\Support\Cajas\HandlesCashBoxActionErrors;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListCashBoxTypes extends ListRecords
{
    use HandlesCashBoxActionErrors;

    protected static string $resource = CashBoxTypeResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getTitle(): string
    {
        return 'Tipos y plantillas de cajas';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuevo tipo de caja')->modal()->modalWidth('5xl')->stickyModalHeader()->stickyModalFooter()->createAnother(false)
            ->modalSubmitActionLabel('Crear tipo y plantilla')->using(fn (#[\SensitiveParameter] array $data): CashBoxType => app(SaveCashBoxTypeAction::class)->execute(auth()->user(), $data))];
    }
}
