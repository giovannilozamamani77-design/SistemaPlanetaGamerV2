<?php

namespace Database\Seeders\Tarjetas;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CardSalesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $p = Permission::findOrCreate('tarjetas.ventas.registrar', 'web');
            if ($p->wasRecentlyCreated) {
                foreach (['administrador', 'encargado', 'cajera'] as $role) {
                    Role::findByName($role)->givePermissionTo($p);
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
