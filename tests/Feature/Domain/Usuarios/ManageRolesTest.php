<?php

use App\Domain\Usuarios\Actions\SaveRoleAction;
use App\Domain\Usuarios\Actions\SaveUserAction;
use App\Filament\Resources\Usuarios\Roles\Pages\ManageRoles;
use App\Filament\Resources\Usuarios\Roles\RoleResource;
use App\Filament\Resources\Usuarios\Users\Pages\EditUser;
use App\Filament\Resources\Usuarios\Users\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function roleManager(): User
{
    app(UsuariosPermissionsSeeder::class)->run();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');

    return $admin;
}

it('configures shared role permissions through the real modal and audits once', function () {
    $admin = roleManager();
    $role = Role::findByName('cajera');
    $cashier = User::factory()->create();
    $cashier->assignRole($role);
    $this->actingAs($admin);
    Livewire::test(ManageRoles::class)
        ->callTableAction('edit', $role, ['name' => 'cajera', 'permissions' => ['panel.acceder', 'perfil.ver']])
        ->assertHasNoActionErrors();
    expect($cashier->fresh()->can('perfil.actualizar'))->toBeFalse();
    expect($cashier->fresh()->can('perfil.ver'))->toBeTrue();
    app(SaveRoleAction::class)->execute($admin, ['name' => 'cajera', 'permissions' => ['panel.acceder', 'perfil.ver']], $role);
    expect(DB::table('role_audits')->count())->toBe(1);
    expect(DB::table('role_audits')->value('actor_user_id'))->toBe($admin->id);
});

it('creates a custom role through its modal without changing predefined roles', function () {
    $this->actingAs(roleManager());
    Livewire::test(ManageRoles::class)->callAction('create', ['name' => 'consulta', 'permissions' => ['panel.acceder', 'perfil.ver']])
        ->assertHasNoActionErrors();
    expect(Role::findByName('consulta')->hasPermissionTo('perfil.ver'))->toBeTrue();
    expect(Role::findByName('administrador')->hasPermissionTo('usuarios.actualizar'))->toBeTrue();
});

it('blocks operational users from role routes and the action even with individual grants', function (string $name) {
    roleManager();
    $user = User::factory()->create();
    $user->assignRole($name);
    $user->givePermissionTo(['usuarios.ver', 'usuarios.actualizar', 'usuarios.permisos.asignar']);
    $this->actingAs($user)->get(RoleResource::getUrl())->assertForbidden();
    expect(fn () => app(SaveRoleAction::class)->execute($user, ['name' => 'injected', 'permissions' => []]))
        ->toThrow(AuthorizationException::class);
})->with(['cajera', 'encargado']);

it('retains an administrator who can manage access when role permissions change', function () {
    $admin = roleManager();
    $role = Role::findByName('administrador');
    expect(fn () => app(SaveRoleAction::class)->execute($admin, ['name' => 'administrador', 'permissions' => ['perfil.ver']], $role))
        ->toThrow(ValidationException::class);
    expect($role->fresh()->hasPermissionTo('usuarios.actualizar'))->toBeTrue();
    expect($admin->fresh()->can('usuarios.permisos.asignar'))->toBeTrue();
    expect(DB::table('role_audits')->count())->toBe(0);
});

it('honors individual restrictions and revoked access to role management', function () {
    $admin = roleManager();
    $permission = Permission::findByName('usuarios.permisos.asignar');
    $admin->restrictedPermissions()->attach($permission);
    $this->actingAs($admin->fresh())->get(RoleResource::getUrl())->assertForbidden();
});

it('rejects role renaming and unknown permissions', function (array $data) {
    $admin = roleManager();
    expect(fn () => app(SaveRoleAction::class)->execute($admin, $data, Role::findByName('cajera')))
        ->toThrow(ValidationException::class);
    expect(Role::findByName('cajera')->name)->toBe('cajera');
})->with([
    'rename' => [['name' => 'otra', 'permissions' => []]],
    'permission' => [['name' => 'cajera', 'permissions' => ['inventado.permitir']]],
]);

it('rolls back shared permissions if the role audit fails', function () {
    $admin = roleManager();
    DB::unprepared("CREATE TRIGGER reject_role_audit BEFORE INSERT ON role_audits BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
    expect(fn () => app(SaveRoleAction::class)->execute($admin, ['name' => 'cajera', 'permissions' => ['panel.acceder']], Role::findByName('cajera')))
        ->toThrow(QueryException::class);
    expect(Role::findByName('cajera')->hasPermissionTo('perfil.actualizar'))->toBeTrue();
});

it('creates users in the floating modal through the audited domain action', function () {
    $admin = roleManager();
    $this->actingAs($admin);
    $page = Livewire::test(ListUsers::class)->assertActionExists('create', checkActionUsing: fn (Action $action): bool => $action->getUrl() === null)->mountAction('create');
    $page->assertActionMounted('create')
        ->setActionData(['name' => 'Cajera modal', 'email' => 'modal@example.test', 'password' => 'safe-password-123', 'is_active' => true, 'roles' => 'cajera'])->callMountedAction()
        ->assertHasNoActionErrors();
    $cashier = User::where('email', 'modal@example.test')->firstOrFail();
    expect($cashier->hasRole('cajera'))->toBeTrue();
    expect(DB::table('user_audits')->where('subject_user_id', $cashier->id)->value('actor_user_id'))->toBe($admin->id);
});

it('preserves existing individual exceptions when editing ordinary user details', function () {
    $admin = roleManager();
    $cashier = app(SaveUserAction::class)->execute($admin, [
        'name' => 'Cajera', 'email' => 'exceptions@example.test', 'password' => 'safe-password-123', 'is_active' => true,
        'roles' => ['cajera'], 'permissions' => ['usuarios.ver'], 'restrictions' => ['perfil.actualizar'],
    ]);
    $this->actingAs($admin);
    Livewire::test(EditUser::class, ['record' => $cashier->id])->fillForm(['name' => 'Nombre cambiado'])->call('save')->assertHasNoFormErrors();
    expect($cashier->fresh()->can('usuarios.ver'))->toBeTrue();
    expect($cashier->fresh()->can('perfil.actualizar'))->toBeFalse();
});
