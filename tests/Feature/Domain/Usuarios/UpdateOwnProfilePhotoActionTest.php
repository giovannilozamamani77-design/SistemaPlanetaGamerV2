<?php

use App\Domain\Usuarios\Actions\UpdateOwnProfilePhotoAction;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use App\Models\User;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function photoProfileUser(): User
{
    app(UsuariosPermissionsSeeder::class)->run();
    Storage::fake('profile_photos');
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $user = User::factory()->create();
    $user->assignRole('cajera');

    return $user;
}

it('keeps photo completely optional and does not create an audit for an empty submission', function () {
    $user = photoProfileUser();
    app(UpdateOwnProfilePhotoAction::class)->execute($user);
    app(UpdateOwnProfilePhotoAction::class)->execute($user, remove: true);

    expect($user->fresh()->profile_photo_path)->toBeNull();
    expect(DB::table('user_audits')->count())->toBe(0);
    Storage::disk('profile_photos')->assertDirectoryEmpty('/');
});

it('stores an uploaded image privately and serves only the authenticated users photo', function () {
    $user = photoProfileUser();
    $saved = app(UpdateOwnProfilePhotoAction::class)->execute($user, UploadedFile::fake()->image('selfie.jpg', 200, 200));
    $other = User::factory()->create();
    $other->assignRole('cajera');

    Storage::disk('profile_photos')->assertExists($saved->profile_photo_path);
    expect($saved->profile_photo_path)->toStartWith($user->id.'/');
    expect($saved->toArray())->not->toHaveKey('profile_photo_path');
    $this->actingAs($user)->get(route('perfil.foto', ['path' => '../other.jpg', 'user_id' => $other->id]))
        ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($user->getFilamentAvatarUrl())->toContain('inicio/mi-foto');
    $this->actingAs($other)->get(route('perfil.foto', ['user_id' => $user->id]))->assertNotFound();
    expect($user->getFilamentAvatarUrl())->toBeNull();
});

it('replaces and removes only the actors photo while keeping identity and history', function () {
    $user = photoProfileUser();
    $first = app(UpdateOwnProfilePhotoAction::class)->execute($user, UploadedFile::fake()->image('one.png'))->profile_photo_path;
    $second = app(UpdateOwnProfilePhotoAction::class)->execute($user, UploadedFile::fake()->image('two.png'))->profile_photo_path;

    Storage::disk('profile_photos')->assertMissing($first);
    Storage::disk('profile_photos')->assertExists($second);
    app(UpdateOwnProfilePhotoAction::class)->execute($user, remove: true);
    Storage::disk('profile_photos')->assertMissing($second);
    expect($user->fresh()->profile_photo_path)->toBeNull();
    expect($user->hasRole('cajera'))->toBeTrue();
    expect(DB::table('user_audits')->where('action', 'profile_photo_updated')->count())->toBe(3);
    expect(DB::table('user_audits')->pluck('changes')->join(''))->not->toContain('one.png', $first, $second);
});

it('rejects invalid images and never accepts a client supplied stored path', function ($file) {
    $user = photoProfileUser();

    expect(fn () => app(UpdateOwnProfilePhotoAction::class)->execute($user, $file))
        ->toThrow(ValidationException::class);
    expect($user->fresh()->profile_photo_path)->toBeNull();
    Storage::disk('profile_photos')->assertDirectoryEmpty('/');
})->with([
    'svg' => [fn () => UploadedFile::fake()->createWithContent('picture.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
    'non image' => [fn () => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf')],
    'too large' => [fn () => UploadedFile::fake()->image('huge.jpg')->size(2049)],
    'dimensions' => [fn () => UploadedFile::fake()->image('wide.jpg', 4097, 10)],
    'stored path' => [fn () => 'another-user/existing.jpg'],
]);

it('refuses photo changes when updating is restricted or the user becomes inactive', function () {
    $user = photoProfileUser();
    $user->restrictedPermissions()->attach(Permission::findByName('perfil.actualizar', 'web'));

    expect(fn () => app(UpdateOwnProfilePhotoAction::class)->execute($user, UploadedFile::fake()->image('photo.jpg')))
        ->toThrow(AuthorizationException::class);
    $user->restrictedPermissions()->detach();
    $user->is_active = false;
    $user->save();
    expect(fn () => app(UpdateOwnProfilePhotoAction::class)->execute($user->fresh(), remove: true))
        ->toThrow(AuthorizationException::class);
    Storage::disk('profile_photos')->assertDirectoryEmpty('/');
});

it('rolls back the database and removes the new file when auditing fails', function () {
    $user = photoProfileUser();
    $old = app(UpdateOwnProfilePhotoAction::class)->execute($user, UploadedFile::fake()->image('before.jpg'))->profile_photo_path;
    DB::statement("CREATE TRIGGER reject_photo_audit BEFORE INSERT ON user_audits BEGIN SELECT RAISE(ABORT, 'audit failure'); END;");

    expect(fn () => app(UpdateOwnProfilePhotoAction::class)->execute($user, UploadedFile::fake()->image('after.jpg')))
        ->toThrow(QueryException::class);
    expect($user->fresh()->profile_photo_path)->toBe($old);
    expect(Storage::disk('profile_photos')->allFiles())->toBe([$old]);
});

it('requires authentication and profile viewing permission to retrieve a photo', function () {
    $user = photoProfileUser();
    app(UpdateOwnProfilePhotoAction::class)->execute($user, UploadedFile::fake()->image('photo.jpg'));
    $this->get(route('perfil.foto'))->assertRedirect(Filament::getLoginUrl());
    $user->restrictedPermissions()->attach(Permission::findByName('perfil.ver', 'web'));
    $this->actingAs($user)->get(route('perfil.foto'))->assertForbidden();
});

it('uploads and removes a photo using the actual profile actions', function () {
    $user = photoProfileUser();
    $this->actingAs($user);
    Livewire::test(EditOwnProfile::class)
        ->assertSee('MI ESPACIO PERSONAL')
        ->assertSee('Información personal')
        ->assertSee('Seguridad de tu cuenta')
        ->callAction('fotoPerfil', data: ['photo' => UploadedFile::fake()->image('avatar.png')])
        ->assertHasNoActionErrors();
    expect($user->fresh()->profile_photo_path)->not->toBeNull();

    Livewire::test(EditOwnProfile::class)->callAction('quitarFotoPerfil')->assertHasNoActionErrors();
    expect($user->fresh()->profile_photo_path)->toBeNull();
});

it('hides photo controls when editing is individually restricted', function () {
    $user = photoProfileUser();
    $user->restrictedPermissions()->attach(Permission::findByName('perfil.actualizar', 'web'));
    $this->actingAs($user);

    Livewire::test(EditOwnProfile::class)
        ->assertActionHidden('fotoPerfil')
        ->assertDontSee('Cambiar foto');
});

it('does not offer photo removal before a photo exists', function () {
    $user = photoProfileUser();
    $this->actingAs($user);

    Livewire::test(EditOwnProfile::class)
        ->assertActionHidden('quitarFotoPerfil')
        ->assertDontSee('Quitar foto');
});
