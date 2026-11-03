<?php

namespace Database\Seeders\Usuarios;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UsuariosPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::transaction(function (): void {
            $common = ['panel.acceder', 'perfil.ver', 'perfil.actualizar'];
            $administrative = ['usuarios.ver', 'usuarios.crear', 'usuarios.actualizar', 'usuarios.permisos.asignar'];
            foreach (array_merge($common, $administrative) as $name) {
                Permission::findOrCreate($name, 'web');
            }
            $pinPermission = Permission::findOrCreate('usuarios.pin.administrar', 'web');
            if ($pinPermission->wasRecentlyCreated) {
                Role::where('name', 'administrador')->where('guard_name', 'web')->get()->each(fn (Role $role): Role => $role->givePermissionTo($pinPermission));
            }
            foreach (['administrador', 'encargado', 'cajera'] as $name) {
                $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                if ($role->wasRecentlyCreated) {
                    $role->syncPermissions($name === 'administrador' ? array_merge($common, $administrative, ['usuarios.pin.administrar']) : $common);
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
