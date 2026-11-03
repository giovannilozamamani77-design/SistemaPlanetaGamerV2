<?php

namespace Database\Seeders\Tarjetas;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CardCashFundingPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $p = Permission::findOrCreate('tarjetas.efectivo.aportar', 'web');
            if ($p->wasRecentlyCreated) {
                foreach (['administrador', 'cajera'] as $role) {
                    Role::findByName($role, 'web')->givePermissionTo($p);
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
