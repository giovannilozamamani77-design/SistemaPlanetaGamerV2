<?php

use App\Domain\Usuarios\Actions\SaveUserAction;
use App\Filament\Resources\Usuarios\Users\Pages\CreateUser;
use App\Filament\Resources\Usuarios\Users\Pages\EditUser;
use App\Filament\Resources\Usuarios\Users\Pages\ListUsers;
use App\Filament\Resources\Usuarios\Users\UserResource;
use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function usersAdmin(): User
{
    app(UsuariosPermissionsSeeder::class)->run();
    $admin = User::factory()->create();
    $admin->assignRole('administrador');

    return $admin;
}
function usersPayload(array $changes = []): array
{
    return array_replace(['name' => 'Nueva cajera', 'email' => 'new@example.test', 'password' => 'secure-password-123', 'is_active' => true, 'roles' => 'cajera', 'permissions' => [], 'restrictions' => []], $changes);
}
it('creates a user with role and a redacted audit in one transaction', function () {
    $admin = usersAdmin();
    $user = app(SaveUserAction::class)->execute($admin, usersPayload(['remember_token' => 'injected']));
    expect($user->hasRole('cajera'))->toBeTrue();
    expect(Hash::check('secure-password-123', $user->password))->toBeTrue();
    expect($user->remember_token)->toBeNull();
    $audit = DB::table('user_audits')->first();
    expect($audit->actor_user_id)->toBe($admin->id);
    expect($audit->changes)->not->toContain('secure-password-123')->not->toContain($user->password);
});
it('blocks disabling, demoting or restricting the last administrator', function (array $changes) {
    $admin = usersAdmin();
    try {
        app(SaveUserAction::class)->execute($admin, usersPayload(array_replace(['email' => $admin->email, 'password' => null], $changes)), $admin);
        $this->fail('Expected validation failure');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.roles');
    }
    expect($admin->fresh()->is_active)->toBeTrue();
    expect($admin->fresh()->hasRole('administrador'))->toBeTrue();
    expect(DB::table('user_audits')->count())->toBe(0);
})->with([
    'deactivate' => [['is_active' => false, 'roles' => ['administrador']]],
    'demote' => [['roles' => 'cajera']],
    'restrict' => [['roles' => ['administrador'], 'restrictions' => ['panel.acceder']]],
]);
it('rejects unauthorized writes without changing users', function (string $role) {
    usersAdmin();
    $actor = User::factory()->create();
    $actor->assignRole($role);
    expect(fn () => app(SaveUserAction::class)->execute($actor, usersPayload()))->toThrow(AuthorizationException::class);
    expect(User::count())->toBe(2);
    expect(DB::table('user_audits')->count())->toBe(0);
})->with(['cajera', 'encargado']);
it('preserves the password on edit and does not duplicate an unchanged audit', function () {
    $admin = usersAdmin();
    $user = app(SaveUserAction::class)->execute($admin, usersPayload());
    $hash = $user->password;
    app(SaveUserAction::class)->execute($admin, usersPayload(['password' => null]), $user);
    expect($user->fresh()->password)->toBe($hash);
    expect(DB::table('user_audits')->count())->toBe(1);
});
it('authorizes direct resource urls and creates users through the real form', function () {
    $admin = usersAdmin();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin)->get(UserResource::getUrl())->assertOk();
    Livewire::test(CreateUser::class)->fillForm(usersPayload())->call('create')->assertHasNoFormErrors();
    expect(User::where('email', 'new@example.test')->firstOrFail()->hasRole('cajera'))->toBeTrue();
    $cashier = User::where('email', 'new@example.test')->firstOrFail();

});
it('edits assigned roles through the real form', function () {
    $admin = usersAdmin();
    $user = User::factory()->create();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    Livewire::test(EditUser::class, ['record' => $user->id])->fillForm(['roles' => 'cajera', 'permissions' => [], 'restrictions' => []])->call('save')->assertHasNoFormErrors();
    expect($user->fresh()->hasRole('cajera'))->toBeTrue();
});

it('forbids users management urls for operational roles', function (string $role) {
    usersAdmin();
    $user = User::factory()->create();
    $user->assignRole($role);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($user)->get(UserResource::getUrl())->assertForbidden();
    $this->get(UserResource::getUrl('create'))->assertForbidden();
})->with(['cajera', 'encargado']);
it('rolls back all writes when audit storage fails', function () {
    $admin = usersAdmin();
    DB::unprepared("CREATE TRIGGER reject_audit BEFORE INSERT ON user_audits BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
    expect(fn () => app(SaveUserAction::class)->execute($admin, usersPayload()))->toThrow(QueryException::class);
    expect(User::count())->toBe(1);
    expect(DB::table('model_has_roles')->count())->toBe(1);
});
it('allows deactivation when another administrator retains access', function () {
    $admin = usersAdmin();
    $second = User::factory()->create();
    $second->assignRole('administrador');
    app(SaveUserAction::class)->execute($admin, usersPayload(['email' => $second->email, 'password' => null, 'roles' => ['administrador'], 'is_active' => false]), $second);
    expect($second->fresh()->is_active)->toBeFalse();
    expect(DB::table('user_audits')->count())->toBe(1);
});

it('rejects invalid fields without creating a user', function (array $changes, string $field) {
    $admin = usersAdmin();
    try {
        app(SaveUserAction::class)->execute($admin, usersPayload($changes));
        $this->fail('Expected invalid input to be rejected');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect(User::count())->toBe(1);
})->with([
    'email' => [['email' => 'invalid'], 'email'],
    'password' => [['password' => '123'], 'password'],
    'missing role' => [['roles' => []], 'roles'],
    'invalid role' => [['roles' => ['inventado']], 'roles.0'],
    'invalid permission' => [['permissions' => ['inventado']], 'permissions.0'],
    'invalid restriction' => [['restrictions' => ['inventado']], 'restrictions.0'],
]);
it('invalidates an existing user instance after changing its restrictions', function () {
    $admin = usersAdmin();
    $user = app(SaveUserAction::class)->execute($admin, usersPayload());
    expect($user->can('panel.acceder'))->toBeTrue();
    app(SaveUserAction::class)->execute($admin, usersPayload(['password' => null, 'restrictions' => ['panel.acceder']]), $user);
    expect($user->can('panel.acceder'))->toBeFalse();
});

it('does not allow operational roles to administer users even with explicit sensitive grants', function (string $role) {
    usersAdmin();
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $actor->givePermissionTo(['usuarios.crear', 'usuarios.actualizar', 'usuarios.permisos.asignar']);
    expect(fn () => app(SaveUserAction::class)->execute($actor, usersPayload()))->toThrow(AuthorizationException::class);
    expect(User::count())->toBe(2);
})->with(['cajera', 'encargado']);

it('rejects more than one role without creating a user or an audit', function () {
    $admin = usersAdmin();
    expect(fn () => app(SaveUserAction::class)->execute($admin, usersPayload(['roles' => ['cajera', 'encargado']])))
        ->toThrow(ValidationException::class);
    expect(User::count())->toBe(1);
    expect(DB::table('user_audits')->count())->toBe(0);
});

it('chooses one role in the edit modal and replaces the previous assignment', function () {
    $admin = usersAdmin();
    $user = app(SaveUserAction::class)->execute($admin, usersPayload());
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    Livewire::test(ListUsers::class)
        ->callTableAction('edit', $user, ['roles' => 'encargado'])->assertHasNoActionErrors();
    expect($user->fresh()->getRoleNames()->all())->toBe(['encargado']);
});

it('initializes the first administrator with one role replacing an existing operational role', function () {
    app(UsuariosPermissionsSeeder::class)->run();
    $user = User::factory()->create();
    $user->assignRole('cajera');
    $this->artisan('usuarios:inicializar-admin', ['email' => $user->email])->assertSuccessful();
    expect($user->fresh()->getRoleNames()->all())->toBe(['administrador']);
});

it('rejects multiple roles on update while preserving the existing role and audit', function () {
    $admin = usersAdmin();
    $user = app(SaveUserAction::class)->execute($admin, usersPayload());
    expect(fn () => app(SaveUserAction::class)->execute($admin, usersPayload(['password' => null, 'roles' => ['cajera', 'encargado']]), $user))
        ->toThrow(ValidationException::class);
    expect($user->fresh()->getRoleNames()->all())->toBe(['cajera']);
    expect(DB::table('user_audits')->count())->toBe(1);
});
