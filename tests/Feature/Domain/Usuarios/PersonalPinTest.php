<?php

use App\Domain\Usuarios\Actions\SetUserPinAction;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use App\Filament\Resources\Usuarios\Users\Pages\EditUser;
use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
function pinAdmin(): User
{
    app(UsuariosPermissionsSeeder::class)->run();
    $user = User::factory()->create();
    $user->assignRole('administrador');

    return $user;
}
it('stores only a protected PIN and records the administrator without exposing secrets', function () {
    $admin = pinAdmin();
    $user = User::factory()->create();
    $user->assignRole('cajera');
    app(SetUserPinAction::class)->execute($admin, $user, '0012');
    expect(Hash::check('0012', $user->pin_hash))->toBeTrue();
    expect($user->toArray())->not->toHaveKey('pin_hash');
    $audit = DB::table('user_audits')->first();
    expect($audit->actor_user_id)->toBe($admin->id);
    expect($audit->subject_user_id)->toBe($user->id);
    expect($audit->changes)->not->toContain('0012')->not->toContain($user->pin_hash);
});
it('rejects non administrators even when they receive the PIN permission', function (string $role) {
    $admin = pinAdmin();
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->givePermissionTo('usuarios.pin.administrar');
    expect(fn () => app(SetUserPinAction::class)->execute($user, $user, '1234'))->toThrow(AuthorizationException::class);
    expect($user->fresh()->pin_hash)->toBeNull();
})->with(['cajera', 'encargado']);
it('honors individual restrictions on administrators', function () {
    $admin = pinAdmin();
    $admin->restrictedPermissions()->attach(Permission::findByName('usuarios.pin.administrar', 'web'));
    expect(fn () => app(SetUserPinAction::class)->execute($admin, $admin, '1234'))->toThrow(AuthorizationException::class);
});
it('rejects malformed PIN values', function (string $pin) {
    $admin = pinAdmin();
    expect(fn () => app(SetUserPinAction::class)->execute($admin, $admin, $pin))->toThrow(ValidationException::class);
    expect(DB::table('user_audits')->count())->toBe(0);
})->with(['', '123', '1234567890123', 'abcd', '12 34']);
it('does not duplicate an unchanged PIN audit and revokes the old PIN when reset', function () {
    $admin = pinAdmin();
    app(SetUserPinAction::class)->execute($admin, $admin, '1234');
    app(SetUserPinAction::class)->execute($admin, $admin, '1234');
    expect(DB::table('user_audits')->count())->toBe(1);
    app(SetUserPinAction::class)->execute($admin, $admin, '5678');
    expect(fn () => app(VerifyPersonalPinAction::class)->execute($admin, '1234'))->toThrow(ValidationException::class);
    app(VerifyPersonalPinAction::class)->execute($admin, '5678');
    expect(DB::table('user_audits')->where('action', 'pin_verified')->count())->toBe(1);
});
it('blocks verification after five failed attempts even when the next PIN is correct', function () {
    $admin = pinAdmin();
    app(SetUserPinAction::class)->execute($admin, $admin, '1234');
    RateLimiter::clear('usuarios:pin:user:'.$admin->id);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect(fn () => app(VerifyPersonalPinAction::class)->execute($admin, '0000'))->toThrow(ValidationException::class);
    }
    expect(fn () => app(VerifyPersonalPinAction::class)->execute($admin, '1234'))->toThrow(ValidationException::class);
    expect(DB::table('user_audits')->where('action', 'pin_verified')->count())->toBe(0);
    $this->travel(61)->seconds();
    app(VerifyPersonalPinAction::class)->execute($admin, '1234');
    expect(DB::table('user_audits')->where('action', 'pin_verified')->count())->toBe(1);
});
it('rejects inactive identities during verification', function () {
    $admin = pinAdmin();
    app(SetUserPinAction::class)->execute($admin, $admin, '1234');
    $admin->is_active = false;
    $admin->save();
    RateLimiter::clear('usuarios:pin:user:'.$admin->id);
    expect(fn () => app(VerifyPersonalPinAction::class)->execute($admin, '1234'))->toThrow(AuthorizationException::class);
});
it('does not create attendance records merely by verifying the PIN', function () {
    $admin = pinAdmin();
    app(SetUserPinAction::class)->execute($admin, $admin, '1234');
    RateLimiter::clear('usuarios:pin:user:'.$admin->id);
    app(VerifyPersonalPinAction::class)->execute($admin, '1234');
    expect(DB::table('user_audits')->where('action', 'pin_verified')->first()->changes)->toBe('{}');
});
it('rolls back PIN updates when audit storage fails', function () {
    $admin = pinAdmin();
    DB::unprepared("CREATE TRIGGER reject_pin_audit BEFORE INSERT ON user_audits BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
    expect(fn () => app(SetUserPinAction::class)->execute($admin, $admin, '1234'))->toThrow(QueryException::class);
    expect($admin->fresh()->pin_hash)->toBeNull();
});
it('configures a PIN through the administrator action in Filament', function () {
    $admin = pinAdmin();
    $user = User::factory()->create();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    Livewire::test(EditUser::class, ['record' => $user->id])->callAction('setPin', data: ['pin' => '0012', 'pinConfirmation' => '0012'])->assertHasNoActionErrors();
    expect(Hash::check('0012', $user->fresh()->pin_hash))->toBeTrue();
});
it('keeps existing customized roles while introducing the new administrator PIN permission', function () {
    app(UsuariosPermissionsSeeder::class)->run();
    $role = Role::findByName('administrador', 'web');
    $role->revokePermissionTo('usuarios.ver');
    app(UsuariosPermissionsSeeder::class)->run();
    expect($role->fresh()->hasPermissionTo('usuarios.ver'))->toBeFalse();
    expect($role->hasPermissionTo('usuarios.pin.administrar'))->toBeTrue();
});

it('persists failed attempt counts when using database cache', function () {
    $admin = pinAdmin();
    app(SetUserPinAction::class)->execute($admin, $admin, '1234');
    config(['cache.limiter' => 'database']);
    app()->forgetInstance(Illuminate\Cache\RateLimiter::class);
    RateLimiter::clearResolvedInstance(Illuminate\Cache\RateLimiter::class);
    $key = 'usuarios:pin:user:'.$admin->id;
    RateLimiter::clear($key);
    expect(fn () => app(VerifyPersonalPinAction::class)->execute($admin, '0000'))->toThrow(ValidationException::class);
    expect(RateLimiter::attempts($key))->toBe(1);
});

it('allows a cashier to change only their own PIN from personal data', function () {
    $admin = pinAdmin();
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($cashier);
    Livewire::test(EditOwnProfile::class)
        ->callAction('pinPersonal', data: ['pin' => '6789', 'pinConfirmation' => '6789', 'user_id' => $admin->id])
        ->assertHasNoActionErrors();
    expect(Hash::check('6789', $cashier->fresh()->pin_hash))->toBeTrue();
    expect($admin->fresh()->pin_hash)->toBeNull();
    expect(fn () => app(SetUserPinAction::class)->execute($cashier, $admin, '6789'))
        ->toThrow(AuthorizationException::class);
});

it('respects profile restrictions and inactive status when changing an own PIN', function () {
    pinAdmin();
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $cashier->restrictedPermissions()->attach(Permission::findByName('perfil.actualizar', 'web'));
    expect(fn () => app(SetUserPinAction::class)->executeOwn($cashier, '1234'))->toThrow(AuthorizationException::class);
    $cashier->restrictedPermissions()->detach();
    $cashier->is_active = false;
    $cashier->save();
    expect(fn () => app(SetUserPinAction::class)->executeOwn($cashier->fresh(), '1234'))->toThrow(AuthorizationException::class);
    expect($cashier->fresh()->pin_hash)->toBeNull();
});
