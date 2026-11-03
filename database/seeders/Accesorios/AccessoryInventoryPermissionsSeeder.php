<?php

namespace Database\Seeders\Accesorios;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessoryInventoryPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['accesorios.inventario.ver' => ['administrador', 'encargado', 'cajera'], 'accesorios.inventario.gestionar' => ['administrador']] as $name => $roles) {
            $p = Permission::findOrCreate($name, 'web');
            if ($p->wasRecentlyCreated) {
                foreach ($roles as $role) {
                    Role::findByName($role, 'web')->givePermissionTo($p);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
