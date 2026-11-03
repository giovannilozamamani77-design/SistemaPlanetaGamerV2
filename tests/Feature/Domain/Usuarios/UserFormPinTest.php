<?php

use App\Domain\Usuarios\Actions\SaveUserAction;
use App\Domain\Usuarios\Actions\SetUserPinAction;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Filament\Resources\Usuarios\Users\Pages\EditUser;
use App\Filament\Resources\Usuarios\Users\Pages\ListUsers;
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

function userFormPinAdmin(): User
{
    app(UsuariosPermissionsSeeder::class)->run();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');

    return $admin;
}

function userFormPinPayload(array $changes = []): array
{
    return array_replace(['name' => 'Cuenta con PIN', 'email' => 'pin-form@example.test', 'password' => 'safe-password-123', 'is_active' => true, 'roles' => 'cajera', 'permissions' => [], 'restrictions' => [], 'pin' => '0045', 'pin_confirmation' => '0045'], $changes);
}

it('sets an initial PIN when creating a user in the real modal', function () {
    $this->actingAs(userFormPinAdmin());
    Livewire::test(ListUsers::class)->callAction('create', userFormPinPayload())->assertHasNoActionErrors();
    $user = User::where('email', 'pin-form@example.test')->firstOrFail();
    expect(Hash::check('0045', $user->pin_hash ?? ''))->toBeTrue();
    expect(DB::table('user_audits')->where('action', 'pin_updated')->count())->toBe(1);
    expect(DB::table('user_audits')->pluck('changes')->implode(''))->not->toContain('0045')->not->toContain($user->pin_hash);
});

it('changes a PIN through the edit user form and preserves it when left blank', function () {
    $admin = userFormPinAdmin();
    $user = app(SaveUserAction::class)->execute($admin, userFormPinPayload());
    $this->actingAs($admin);
    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['pin' => '0098', 'pin_confirmation' => '0098'])->call('save')->assertHasNoFormErrors();
    expect(Hash::check('0098', $user->fresh()->pin_hash ?? ''))->toBeTrue();
    $hash = $user->fresh()->pin_hash;
    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['name' => 'Nombre actualizado', 'pin' => '', 'pin_confirmation' => ''])->call('save')->assertHasNoFormErrors();
    expect($user->fresh()->pin_hash)->toBe($hash);
});

it('rejects invalid or unconfirmed PINs before creating any account', function (array $changes) {
    $admin = userFormPinAdmin();
    expect(fn () => app(SaveUserAction::class)->execute($admin, userFormPinPayload($changes)))
        ->toThrow(ValidationException::class);
    expect(User::count())->toBe(1);
    expect(DB::table('user_audits')->count())->toBe(0);
})->with([
    'confirmation' => [['pin_confirmation' => '999999']],
    'format' => [['pin' => 'abc1', 'pin_confirmation' => 'abc1']],
]);

it('rejects an injected PIN without its permission and rolls back account creation', function () {
    $admin = userFormPinAdmin();
    $admin->restrictedPermissions()->attach(Permission::findByName('usuarios.pin.administrar'));
    expect(fn () => app(SaveUserAction::class)->execute($admin->fresh(), userFormPinPayload()))
        ->toThrow(AuthorizationException::class);
    expect(User::count())->toBe(1);
    expect(DB::table('user_audits')->count())->toBe(0);
});

it('still creates an account without configuring an optional PIN', function () {
    $admin = userFormPinAdmin();
    $user = app(SaveUserAction::class)->execute($admin, userFormPinPayload(['pin' => '', 'pin_confirmation' => '']));
    expect($user->pin_hash)->toBeNull();
});

it('rolls back user and PIN when the PIN audit cannot be saved', function () {
    $admin = userFormPinAdmin();
    DB::unprepared("CREATE TRIGGER reject_pin_audit BEFORE INSERT ON user_audits WHEN NEW.action = 'pin_updated' BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
    expect(fn () => app(SaveUserAction::class)->execute($admin, userFormPinPayload()))
        ->toThrow(QueryException::class);
    expect(User::count())->toBe(1);
    expect(DB::table('user_audits')->count())->toBe(0);
});

it('rejects a PIN longer than exactly four digits in both administrative and own updates', function () {
    $admin = userFormPinAdmin();
    expect(fn () => app(SetUserPinAction::class)->execute($admin, $admin, '12345'))->toThrow(ValidationException::class);
    expect(fn () => app(SetUserPinAction::class)->executeOwn($admin, '123456'))->toThrow(ValidationException::class);
    expect($admin->fresh()->pin_hash)->toBeNull();
});

it('edits a user and PIN from the real floating table action without losing exceptions', function () {
    $admin = userFormPinAdmin();
    $user = app(SaveUserAction::class)->execute($admin, userFormPinPayload(['permissions' => ['usuarios.ver'], 'restrictions' => ['perfil.actualizar']]));
    $this->actingAs($admin);
    $page = Livewire::test(ListUsers::class);
    expect($page->instance()->getTable()->getAction('edit')->getUrl())->toBeNull();
    expect($page->instance()->getTable()->getRecordUrl($user))->toBeNull();
    $page->callTableAction('edit', $user, ['name' => 'Editada en modal', 'pin' => '0088', 'pin_confirmation' => '0088'])->assertHasNoActionErrors();
    expect($user->fresh()->name)->toBe('Editada en modal');
    expect(Hash::check('0088', $user->fresh()->pin_hash))->toBeTrue();
    expect($user->fresh()->can('usuarios.ver'))->toBeTrue();
    expect($user->fresh()->can('perfil.actualizar'))->toBeFalse();
});

it('does not accept an old longer PIN even if its hash matches', function () {
    $admin = userFormPinAdmin();
    $admin->pin_hash = Hash::make('123456');
    $admin->save();
    expect(fn () => app(VerifyPersonalPinAction::class)->execute($admin, '123456'))->toThrow(ValidationException::class);
    expect(DB::table('user_audits')->where('action', 'pin_verified')->count())->toBe(0);
});
