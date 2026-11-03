<?php

use App\Domain\Usuarios\Actions\UpdateOwnProfileAction;
use App\Filament\Pages\Usuarios\EditOwnProfile;
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
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function profileUser(): User
{
    app(UsuariosPermissionsSeeder::class)->run();
    $user = User::factory()->create();
    $user->assignRole('cajera');

    return $user;
}
it('updates only the actors own identity and ignores injected privileges', function () {
    $user = profileUser();
    $other = User::factory()->create();
    app(UpdateOwnProfileAction::class)->execute($user, [
        'name' => 'Nuevo nombre', 'email' => $user->email,
        'id' => $other->id, 'is_active' => false, 'roles' => ['administrador'], 'permissions' => ['usuarios.crear'],
    ]);
    expect($user->fresh()->name)->toBe('Nuevo nombre');
    expect($user->hasRole('administrador'))->toBeFalse();
    expect($user->is_active)->toBeTrue();
    expect($other->fresh()->name)->toBe($other->name);
    $audit = DB::table('user_audits')->first();
    expect($audit->actor_user_id)->toBe($user->id);
    expect($audit->subject_user_id)->toBe($user->id);
});
it('requires the current password to change sensitive credentials', function (array $change) {
    $user = profileUser();
    $email = $user->email;
    $hash = $user->password;
    expect(fn () => app(UpdateOwnProfileAction::class)->execute($user, array_merge(['name' => $user->name, 'email' => $email, 'currentPassword' => 'wrong'], $change)))->toThrow(ValidationException::class);
    expect($user->fresh()->email)->toBe($email);
    expect($user->password)->toBe($hash);
    expect(DB::table('user_audits')->count())->toBe(0);
})->with([
    'password' => [['password' => 'new-secret-123']],
    'email' => [['email' => 'new-email@example.test']],
]);
it('changes credentials with a valid current password and redacts secrets', function () {
    $user = profileUser();
    app(UpdateOwnProfileAction::class)->execute($user, ['name' => $user->name, 'email' => 'changed@example.test', 'password' => 'new-secret-123', 'currentPassword' => 'password']);
    expect(Hash::check('new-secret-123', $user->password))->toBeTrue();
    expect($user->email_verified_at)->toBeNull();
    expect(DB::table('user_audits')->first()->changes)->not->toContain('new-secret-123')->not->toContain($user->password)->not->toContain('currentPassword');
});
it('rejects disabled users and effective permission restrictions', function (string $blocked) {
    $user = profileUser();
    if ($blocked === 'inactive') {
        $user->is_active = false;
        $user->save();
    } else {
        $user->restrictedPermissions()->attach(Permission::findByName($blocked, 'web'));
    }
    expect(fn () => app(UpdateOwnProfileAction::class)->execute($user, ['name' => 'Blocked', 'email' => $user->email]))->toThrow(AuthorizationException::class);
    expect(DB::table('user_audits')->count())->toBe(0);
})->with(['inactive', 'perfil.ver', 'perfil.actualizar']);
it('rolls back profile writes when the audit fails', function () {
    $user = profileUser();
    $name = $user->name;
    DB::unprepared("CREATE TRIGGER reject_profile_audit BEFORE INSERT ON user_audits BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
    expect(fn () => app(UpdateOwnProfileAction::class)->execute($user, ['name' => 'Changed', 'email' => $user->email]))->toThrow(QueryException::class);
    expect($user->fresh()->name)->toBe($name);
});
it('does not audit unchanged profile retries', function () {
    $user = profileUser();
    $data = ['name' => 'Changed', 'email' => $user->email];
    app(UpdateOwnProfileAction::class)->execute($user, $data);
    app(UpdateOwnProfileAction::class)->execute($user, $data);
    expect(DB::table('user_audits')->count())->toBe(1);
});
it('renders and saves the profile through Filament without logging out after password change', function () {
    $user = profileUser();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($user);
    Livewire::test(EditOwnProfile::class)->fillForm([
        'name' => 'Perfil actualizado', 'email' => $user->email, 'password' => 'new-secret-123', 'passwordConfirmation' => 'new-secret-123', 'currentPassword' => 'password',
    ])->call('save')->assertHasNoFormErrors();
    expect($user->fresh()->name)->toBe('Perfil actualizado');
    expect(Hash::check('new-secret-123', $user->password))->toBeTrue();

    $this->get('/inicio')->assertOk();
});
it('blocks the direct profile url when viewing permission is denied', function () {
    $user = profileUser();
    $user->restrictedPermissions()->attach(Permission::findByName('perfil.ver', 'web'));
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($user)->get(EditOwnProfile::getUrl())->assertForbidden();
});

it('rejects invalid profile values without persisting changes', function (array $change, string $field) {
    $user = profileUser();
    try {
        app(UpdateOwnProfileAction::class)->execute($user, array_merge(['name' => $user->name, 'email' => $user->email], $change));
        $this->fail('Expected validation rejection');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
    expect(DB::table('user_audits')->count())->toBe(0);
})->with([
    'name' => [['name' => ''], 'name'],
    'email' => [['email' => 'invalid'], 'email'],
    'password' => [['password' => '123'], 'password'],
]);
it('rejects the email of another account', function () {
    $user = profileUser();
    $other = User::factory()->create();
    expect(fn () => app(UpdateOwnProfileAction::class)->execute($user, ['name' => $user->name, 'email' => $other->email, 'currentPassword' => 'password']))->toThrow(ValidationException::class);
    expect(DB::table('user_audits')->count())->toBe(0);
});
it('renders the direct profile url for an authorized cashier', function () {
    $user = profileUser();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($user)->get(EditOwnProfile::getUrl())->assertOk();
});
it('blocks a crafted save when updating permission is denied', function () {
    $user = profileUser();
    $user->restrictedPermissions()->attach(Permission::findByName('perfil.actualizar', 'web'));
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($user);
    Livewire::test(EditOwnProfile::class)->fillForm(['name' => 'Blocked'])->call('save')->assertForbidden();
    expect($user->fresh()->name)->toBe($user->name);
});
