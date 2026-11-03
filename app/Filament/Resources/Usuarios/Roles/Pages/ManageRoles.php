<?php

namespace App\Filament\Resources\Usuarios\Roles\Pages;

use App\Domain\Usuarios\Actions\SaveRoleAction;
use App\Filament\Resources\Usuarios\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Spatie\Permission\Models\Role;

class ManageRoles extends ManageRecords
{
    protected static string $resource = RoleResource::class;

    public function getSubheading(): ?string
    {
        return 'Configura el acceso una vez y asigna el rol al crear un usuario. Los cambios afectan a todos sus integrantes.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo rol')->modalSubmitActionLabel('Crear rol')->modalCancelActionLabel('Cancelar')->modalWidth('3xl')->createAnother(false)
                ->using(fn (array $data): Role => app(SaveRoleAction::class)->execute(auth()->user(), $data)),
        ];
    }
}
