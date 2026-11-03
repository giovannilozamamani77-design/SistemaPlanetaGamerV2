<?php

namespace Database\Seeders\Incidencias;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class IncidenciasPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ([
                'incidencias.ver_propios' => ['administrador', 'encargado', 'cajera'],
                'incidencias.reportar' => ['administrador', 'encargado', 'cajera'],
                'incidencias.declarar_aporte' => ['administrador', 'encargado', 'cajera'],
                'incidencias.ver_todos' => ['administrador'],
                'incidencias.confirmar' => ['administrador'],
                'incidencias.reposiciones.confirmar' => ['administrador'],
                'controlhorario.solicitar' => ['administrador', 'encargado', 'cajera'],
                'controlhorario.revisar' => ['administrador'],
                'configuraciones.personal' => ['administrador'],
            ] as $name => $roles) {
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
