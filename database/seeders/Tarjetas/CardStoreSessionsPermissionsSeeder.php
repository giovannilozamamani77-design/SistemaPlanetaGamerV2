<?php

namespace Database\Seeders\Tarjetas;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CardStoreSessionsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (['tarjetas.sesiones.abrir', 'tarjetas.sesiones.cerrar'] as $name) {
                $p = Permission::findOrCreate($name, 'web');
                if ($p->wasRecentlyCreated) {
                    foreach (['administrador', 'encargado', 'cajera'] as $role) {
                        Role::findByName($role, 'web')->givePermissionTo($p);
                    }
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
