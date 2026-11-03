<?php

namespace Database\Seeders\Prestamos;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class LoanPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (['prestamos.objetos.gastos' => ['administrador'], 'prestamos.objetos.vender' => ['administrador', 'encargado', 'cajera'], 'prestamos.anular' => ['administrador'], 'prestamos.pagar_entregar' => ['administrador', 'encargado', 'cajera'], 'prestamos.condiciones.manuales' => ['administrador', 'encargado', 'cajera'], 'prestamos.renovar' => ['administrador', 'encargado', 'cajera'], 'prestamos.ver' => ['administrador', 'encargado', 'cajera'], 'prestamos.registrar' => ['administrador', 'encargado', 'cajera'], 'prestamos.ver_todos' => ['administrador', 'encargado'], 'prestamos.objetos.configurar' => ['administrador'], 'prestamos.fondo.administrar' => ['administrador']] as $name => $roles) {
                $p = Permission::findOrCreate($name, 'web');
                if ($p->wasRecentlyCreated || $name === 'prestamos.condiciones.manuales') {
                    foreach ($roles as $role) {
                        Role::findByName($role, 'web')->givePermissionTo($p);
                    }
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
