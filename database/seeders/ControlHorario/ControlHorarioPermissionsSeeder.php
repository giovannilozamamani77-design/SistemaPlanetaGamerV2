<?php

namespace Database\Seeders\ControlHorario;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ControlHorarioPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $assignments = [
            'controlhorario.ver_propios' => ['administrador', 'encargado', 'cajera'],
            'controlhorario.asistencia.registrar' => ['cajera', 'encargado'],
            'controlhorario.ver_todos' => ['administrador'],
            'controlhorario.programar' => ['administrador'],
        ];
        foreach ($assignments as $name => $roles) {
            $permission = Permission::findOrCreate($name, 'web');
            if ($permission->wasRecentlyCreated) {
                foreach ($roles as $role) {
                    Role::findByName($role, 'web')->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
