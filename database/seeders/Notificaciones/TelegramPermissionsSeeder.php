<?php

namespace Database\Seeders\Notificaciones;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class TelegramPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $p = Permission::findOrCreate('notificaciones.telegram.configurar', 'web');
        if ($p->wasRecentlyCreated) {
            Role::findByName('administrador', 'web')->givePermissionTo($p);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
