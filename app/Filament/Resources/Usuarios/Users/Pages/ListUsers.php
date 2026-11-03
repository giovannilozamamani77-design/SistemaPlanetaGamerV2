<?php

namespace App\Filament\Resources\Usuarios\Users\Pages;

use App\Domain\Usuarios\Actions\SaveUserAction;
use App\Filament\Resources\Usuarios\Users\UserResource;
use App\Filament\Widgets\Usuarios\UsersOverview;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getSubheading(): ?string
    {
        return 'Encuentra a tu equipo, revisa su acceso y consulta la calidad de atención. Usa la búsqueda y los filtros para ubicar una cuenta.';
    }

    protected function getHeaderWidgets(): array
    {
        return [UsersOverview::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo usuario')->modal()
                ->modalHeading('Crear usuario')->modalDescription('Completa sus datos y elige un rol previamente configurado.')
                ->modalSubmitActionLabel('Crear usuario')->modalCancelActionLabel('Cancelar')->modalWidth('2xl')->stickyModalHeader()->stickyModalFooter()->createAnother(false)
                ->using(fn (array $data): User => app(SaveUserAction::class)->execute(auth()->user(), array_replace(['permissions' => [], 'restrictions' => []], $data))),
        ];
    }
}
