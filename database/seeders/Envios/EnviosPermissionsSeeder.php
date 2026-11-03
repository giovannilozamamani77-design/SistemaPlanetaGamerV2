<?php

namespace Database\Seeders\Envios;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class EnviosPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['envios.ver', 'envios.registrar', 'envios.administrar'] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            if ($permission->wasRecentlyCreated) {
                foreach ($name === 'envios.administrar' ? ['administrador'] : ['administrador', 'encargado', 'cajera'] as $role) {
                    if ($record = Role::where('name', $role)->where('guard_name', 'web')->first()) {
                        $record->givePermissionTo($permission);
                    }
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
