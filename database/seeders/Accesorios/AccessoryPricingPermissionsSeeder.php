<?php

namespace Database\Seeders\Accesorios;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessoryPricingPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::findOrCreate('accesorios.precios.configurar', 'web');
        if ($permission->wasRecentlyCreated) {
            Role::findByName('administrador', 'web')->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
