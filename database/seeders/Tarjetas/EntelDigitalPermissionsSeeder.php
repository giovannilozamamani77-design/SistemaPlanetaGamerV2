<?php

namespace Database\Seeders\Tarjetas;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class EntelDigitalPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (['tarjetas.entel.ver', 'tarjetas.entel.registrar'] as $name) {
                $p = Permission::findOrCreate($name, 'web');
                if ($p->wasRecentlyCreated) {
                    Role::findByName('administrador', 'web')->givePermissionTo($p);
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
