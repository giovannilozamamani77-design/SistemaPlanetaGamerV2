<?php

namespace Database\Seeders\Tarjetas;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class TarjetasPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $operational = ['administrador', 'encargado', 'cajera'];
            $assignments = ['tarjetas.inventario.ver' => $operational, 'tarjetas.movimientos.ver' => $operational, 'tarjetas.productos.crear' => ['administrador', 'encargado'], 'tarjetas.precios.editar' => ['administrador'], 'tarjetas.inventario.inicializar' => ['administrador'], 'tarjetas.compras.registrar' => $operational, 'tarjetas.entregas.registrar' => $operational];
            foreach ($assignments as $name => $roles) {
                $permission = Permission::findOrCreate($name, 'web');
                if ($permission->wasRecentlyCreated) {
                    foreach ($roles as $role) {
                        Role::findByName($role, 'web')->givePermissionTo($permission);
                    }
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
