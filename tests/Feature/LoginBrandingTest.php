<?php

use App\Filament\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
});

it('routes the bare domain to the existing panel login', function () {
    $this->get('/')->assertRedirect(route('filament.inicio.auth.login'));
});

it('shows version two branding with the system icon and a clean browser title', function () {
    $response = $this->get('/inicio/login')->assertOk()->assertDontSee('Versión 2')->assertDontSee('Planeta Gamer V2')->assertDontSee('Bienvenido a Planeta Gamer')->assertDontSee('Nueva versión')->assertDontSee('Una nueva versión')
        ->assertSee('branding/planeta-gamer-symbol.png?v=2', false)->assertSee('Ingresar al sistema');
    expect($response->getContent())->toMatch('/<title>\s*Planeta Gamer\s*<\/title>/');
});

it('keeps invalid credentials rejected by native authentication', function () {
    $user = User::factory()->create(['password' => 'CorrectaDePrueba123!']);
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'Incorrecta123!'])
        ->call('authenticate')->assertHasFormErrors(['email']);
    $this->assertGuest();
});

it('keeps authorized access working through the native login', function () {
    $user = User::factory()->create(['password' => 'CorrectaDePrueba123!']);
    Permission::findOrCreate('panel.acceder', 'web');
    $user->givePermissionTo('panel.acceder');
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'CorrectaDePrueba123!'])
        ->call('authenticate')->assertHasNoFormErrors()->assertRedirect();
    $this->assertAuthenticatedAs($user);
});
