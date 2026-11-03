<?php

use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('allows the three initial roles to enter the existing panel', function (string $role) {
    $this->seed(UsuariosPermissionsSeeder::class);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user)->get('/inicio')->assertOk();
})->with(['administrador', 'encargado', 'cajera']);

it('denies panel access to inactive users even with administrator permissions', function () {
    $this->seed(UsuariosPermissionsSeeder::class);
    $user = User::factory()->create(['is_active' => false]);
    $user->assignRole('administrador');
    $this->actingAs($user)->get('/inicio')->assertForbidden();
});

it('gives explicit restrictions priority over role and individual grants', function () {
    $this->seed(UsuariosPermissionsSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('administrador');
    $user->givePermissionTo('usuarios.ver');
    $permission = Permission::findByName('usuarios.ver', 'web');
    $user->restrictedPermissions()->attach($permission);
    expect($user->can('usuarios.ver'))->toBeFalse();
    expect($user->can('usuarios.crear'))->toBeTrue();
});

it('rejects missing permissions and users without a panel grant', function () {
    $this->seed(UsuariosPermissionsSeeder::class);
    $user = User::factory()->create();
    expect($user->can('usuarios.inexistente'))->toBeFalse();
    $this->actingAs($user)->get('/inicio')->assertForbidden();
});

it('preserves customized roles when the initializer is repeated', function () {
    $this->seed(UsuariosPermissionsSeeder::class);
    $role = Role::findByName('cajera', 'web');
    $role->revokePermissionTo('perfil.actualizar');
    $this->seed(UsuariosPermissionsSeeder::class);
    expect($role->fresh()->hasPermissionTo('perfil.actualizar'))->toBeFalse();
    expect(Role::count())->toBe(3);
});

it('reuses permission relations without querying for every menu check', function () {
    $this->seed(UsuariosPermissionsSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('cajera');
    expect($user->can('panel.acceder'))->toBeTrue();
    DB::enableQueryLog();
    DB::flushQueryLog();
    for ($index = 0; $index < 20; $index++) {
        $user->can('perfil.ver');
        $user->can('usuarios.ver');
    }
    expect(DB::getQueryLog())->toHaveCount(0);
    DB::disableQueryLog();
});

it('initializes only the first administrator and tolerates retries for the same user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->artisan('usuarios:inicializar-admin', ['email' => $user->email])->assertSuccessful();
    $this->artisan('usuarios:inicializar-admin', ['email' => $user->email])->assertSuccessful();
    $this->artisan('usuarios:inicializar-admin', ['email' => $other->email])->assertFailed();
    expect($user->fresh()->hasRole('administrador'))->toBeTrue();
    expect($other->fresh()->hasRole('administrador'))->toBeFalse();
});

it('rejects initialization of missing and inactive accounts', function () {
    $user = User::factory()->create(['is_active' => false]);
    $this->artisan('usuarios:inicializar-admin', ['email' => 'missing@example.test'])->assertFailed();
    $this->artisan('usuarios:inicializar-admin', ['email' => $user->email])->assertFailed();
    expect($user->fresh()->hasRole('administrador'))->toBeFalse();
});
