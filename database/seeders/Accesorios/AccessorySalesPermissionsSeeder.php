<?php

namespace Database\Seeders\Accesorios;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessorySalesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['accesorios.ventas.registrar', 'accesorios.ventas.ver'] as $name) {
            $p = Permission::findOrCreate($name, 'web');
            if ($p->wasRecentlyCreated) {
                foreach (['administrador', 'encargado', 'cajera'] as $role) {
                    Role::findByName($role, 'web')->givePermissionTo($p);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
