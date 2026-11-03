<?php

namespace App\Filament\Resources\Cajas\CashBoxes\Pages;

use App\Domain\Cajas\Actions\CreateCashBoxAction;
use App\Domain\Cajas\Models\CashBox;
use App\Filament\Resources\Cajas\CashBoxes\CashBoxResource;
use App\Filament\Support\Cajas\HandlesCashBoxActionErrors;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListCashBoxes extends ListRecords
{
    use HandlesCashBoxActionErrors;

    protected static string $resource = CashBoxResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getTitle(): string
    {
        return 'Configuración de cajas';
    }

    public function getSubheading(): ?string
    {
        return 'Administra las cajas y su capital de referencia. Consulta los cambios sin eliminar el historial.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva caja')->modal()->modalWidth('4xl')->stickyModalHeader()->stickyModalFooter()
                ->modalHeading('Registrar caja')->modalSubmitActionLabel('Registrar caja')->modalCancelActionLabel('Cancelar')
                ->createAnother(false)
                ->using(fn (#[\SensitiveParameter] array $data): CashBox => app(CreateCashBoxAction::class)->execute(auth()->user(), $data)),
        ];
    }
}
