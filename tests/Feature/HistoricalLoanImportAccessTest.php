<?php

use App\Domain\Prestamos\Models\Loan;
use App\Models\User;
use Database\Seeders\Prestamos\LoanPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('allows only authorized active administrators and supervisors to import historical loans', function (string $role, bool $active, bool $allowed) {
    $this->seed([UsuariosPermissionsSeeder::class, LoanPermissionsSeeder::class]);
    $user = User::factory()->create(['is_active' => $active]);
    $user->assignRole($role);
    if ($role === 'cajera') {
        $user->givePermissionTo('prestamos.ver_todos');
    }
    expect(Gate::forUser($user)->allows('importHistorical', Loan::class))->toBe($allowed);
})->with([['administrador', true, true], ['encargado', true, true], ['cajera', true, false], ['administrador', false, false], ['encargado', false, false]]);

it('respects manual-terms restrictions even on an administrator role', function () {
    $this->seed([UsuariosPermissionsSeeder::class, LoanPermissionsSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $permission = Permission::findByName('prestamos.condiciones.manuales', 'web');
    $admin->restrictedPermissions()->attach($permission);
    expect(Gate::forUser($admin->fresh())->allows('importHistorical', Loan::class))->toBeFalse();
});
