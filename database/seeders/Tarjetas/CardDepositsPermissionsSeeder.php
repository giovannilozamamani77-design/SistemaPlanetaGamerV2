<?php

namespace Database\Seeders\Tarjetas;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CardDepositsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (['tarjetas.depositos.registrar' => ['administrador', 'encargado', 'cajera'], 'tarjetas.depositos.configurar' => ['administrador']] as $name => $roles) {
                $p = Permission::findOrCreate($name, 'web');
                if ($p->wasRecentlyCreated) {
                    foreach ($roles as $role) {
                        Role::findByName($role, 'web')->givePermissionTo($p);
                    }
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
