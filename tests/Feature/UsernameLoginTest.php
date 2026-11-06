<?php

use App\Domain\Usuarios\Actions\SaveUserAction;
use App\Filament\Auth\Login;
use App\Filament\Resources\Usuarios\Users\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
});

it('authenticates the same account with email or case insensitive first name', function (string $identifier) {
    $user = User::factory()->create(['name' => 'Giovanni Loza Mamani', 'email' => 'giovanni@example.test', 'password' => 'Correcta123!']);
    Permission::findOrCreate('panel.acceder', 'web');
    $user->givePermissionTo('panel.acceder');
    expect($user->username)->toBe('Giovanni');
    Livewire::test(Login::class)->fillForm(['email' => $identifier, 'password' => 'Correcta123!'])
        ->call('authenticate')->assertHasNoFormErrors()->assertRedirect();
    $this->assertAuthenticatedAs($user);
})->with(['giovanni@example.test', 'Giovanni', '  GIOVANNI  ']);

it('rejects usernames with wrong passwords inactive accounts and missing panel permission', function (string $reason) {
    $user = User::factory()->create(['name' => 'Giovanni Loza', 'password' => 'Correcta123!', 'is_active' => $reason !== 'inactive']);
    Permission::findOrCreate('panel.acceder', 'web');
    if ($reason !== 'permission') {
        $user->givePermissionTo('panel.acceder');
    }
    Livewire::test(Login::class)->fillForm(['email' => 'Giovanni', 'password' => $reason === 'password' ? 'Incorrecta123!' : 'Correcta123!'])
        ->call('authenticate')->assertHasFormErrors(['email']);
    $this->assertGuest();
})->with(['password', 'inactive', 'permission']);

it('keeps the username stable on name edits and normalizes accents for first names', function () {
    $user = User::factory()->create(['name' => '  José Pérez']);
    expect($user->username)->toBe('Jose');
    $user->update(['name' => 'Otro nombre']);
    expect($user->fresh()->username)->toBe('Jose');
});

it('rejects duplicate usernames in the actual user modal and accepts manual alternatives', function () {
    app(UsuariosPermissionsSeeder::class)->run();
    $admin = User::factory()->create(['name' => 'Giovanni Loza']);
    $admin->assignRole('administrador');
    $this->actingAs($admin);
    $payload = ['name' => 'Giovanni Otro', 'email' => 'otro@example.test', 'password' => 'Correcta123!', 'is_active' => true, 'roles' => 'cajera', 'permissions' => [], 'restrictions' => []];
    Livewire::test(ListUsers::class)->callAction('create', array_replace($payload, ['username' => 'GIOVANNI']))->assertHasActionErrors(['username']);
    expect(User::where('email', 'otro@example.test')->exists())->toBeFalse();
    Livewire::test(ListUsers::class)->callAction('create', array_replace($payload, ['username' => 'GioCaja']))->assertHasNoActionErrors();
    $user = User::where('email', 'otro@example.test')->firstOrFail();
    expect($user->username)->toBe('GioCaja')->and($user->username_key)->toBe('giocaja');
    expect(DB::table('user_audits')->where('subject_user_id', $user->id)->value('changes'))->toContain('GioCaja')->not->toContain('Correcta123!');
});

it('protects normalized uniqueness at database level', function () {
    User::factory()->create(['username' => 'GioCaja']);
    expect(fn () => User::factory()->create(['username' => 'gIOcAJA']))->toThrow(UniqueConstraintViolationException::class);
});

it('backfills existing names without changing passwords roles or conflicting identities', function () {
    $one = User::factory()->create(['name' => 'Giovanni Loza']);
    $two = User::factory()->create(['name' => 'giovanni Mamani']);
    $password = $one->password;
    $migration = require database_path('migrations/usuarios/2026_11_06_153230_add_username_to_users_table.php');
    $migration->down();
    $migration->up();
    expect($one->fresh()->username)->toBe('Giovanni')->and($one->fresh()->password)->toBe($password);
    expect($two->fresh()->username)->toBeNull()->and($two->fresh()->email)->toBe($two->email);
});

it('rejects an email as username and manual case duplicates through the domain action', function (mixed $username) {
    app(UsuariosPermissionsSeeder::class)->run();
    $admin = User::factory()->create(['name' => 'Giovanni Loza']);
    $admin->assignRole('administrador');
    try {
        app(SaveUserAction::class)->execute($admin, ['name' => 'Otro nombre', 'username' => $username, 'email' => 'otro@example.test', 'password' => 'Correcta123!', 'is_active' => true, 'roles' => 'cajera', 'permissions' => [], 'restrictions' => []]);
        $this->fail('Expected username validation');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey('username');
    }
    expect(User::count())->toBe(1)->and(DB::table('user_audits')->count())->toBe(0);
})->with(['GIOVANNI', 'otro@example.test', 'nombre con espacios', 'array injection' => [['unexpected']]]);

it('shows a useful username error if a concurrent save wins after validation', function () {
    app(UsuariosPermissionsSeeder::class)->run();
    $admin = User::factory()->create(['name' => 'Giovanni Loza']);
    $admin->assignRole('administrador');
    $this->actingAs($admin);
    DB::unprepared("CREATE TRIGGER username_collision BEFORE INSERT ON users WHEN NEW.username_key = 'cajaotro' BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: users.username_key'); END");
    try {
        Livewire::test(ListUsers::class)->callAction('create', ['name' => 'Otro nombre', 'username' => 'CajaOtro', 'email' => 'otro@example.test', 'password' => 'Correcta123!', 'is_active' => true, 'roles' => 'cajera', 'permissions' => [], 'restrictions' => []])
            ->assertHasActionErrors(['username']);
        expect(User::count())->toBe(1)->and(DB::table('user_audits')->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER username_collision');
    }
});

it('generates the plain first name then suffixes starting at one without changing existing identities', function () {
    $one = User::factory()->create(['name' => 'Giovanni Loza']);
    $two = User::factory()->create(['name' => 'Giovanni Mamani']);
    $three = User::factory()->create(['name' => 'Giovanni Otro']);
    expect($one->username)->toBe('Giovanni')->and($two->username)->toBe('Giovanni1')->and($three->username)->toBe('Giovanni2');
    $two->update(['name' => 'Cambio nombre']);
    expect($two->fresh()->username)->toBe('Giovanni1');
});

it('creates repeated first names automatically through the real modal', function () {
    app(UsuariosPermissionsSeeder::class)->run();
    $admin = User::factory()->create(['name' => 'Giovanni Loza']);
    $admin->assignRole('administrador');
    $this->actingAs($admin);
    Livewire::test(ListUsers::class)->callAction('create', ['name' => 'Giovanni Mamani', 'email' => 'segundo@example.test', 'password' => 'Correcta123!', 'is_active' => true, 'roles' => 'cajera', 'permissions' => [], 'restrictions' => []])->assertHasNoActionErrors();
    expect(User::where('email', 'segundo@example.test')->firstOrFail()->username)->toBe('Giovanni1');
});

it('fills missing usernames with numbered alternatives without renaming existing accounts', function () {
    $one = User::factory()->create(['name' => 'Giovanni Loza']);
    $two = User::factory()->create(['name' => 'Giovanni Mamani']);
    DB::table('users')->where('id', $two->id)->update(['username' => null, 'username_key' => null]);
    $migration = require database_path('migrations/usuarios/2026_11_06_154054_assign_available_usernames_to_existing_users.php');
    $migration->up();
    $migration->up();
    expect($one->fresh()->username)->toBe('Giovanni')->and($two->fresh()->username)->toBe('Giovanni1');
});
