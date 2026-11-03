<?php

namespace Database\Seeders\Cajas;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CajasPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['cajas.catalogo.ver', 'cajas.catalogo.gestionar', 'cajas.liberar'] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            if ($permission->wasRecentlyCreated) {
                Role::findByName('administrador', 'web')->givePermissionTo($permission);
            }
        }
        $operate = Permission::findOrCreate('cajas.operar', 'web');
        if ($operate->wasRecentlyCreated) {
            foreach (['administrador', 'encargado', 'cajera'] as $role) {
                Role::findByName($role, 'web')->givePermissionTo($operate);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
