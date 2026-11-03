<?php

use App\Filament\Pages\ControlHorario\AssignedShifts;
use App\Filament\Pages\ControlHorario\ShiftCoverages;
use App\Filament\Pages\Usuarios\EditOwnProfile;
use App\Filament\Resources\ControlHorario\ScheduledWorkdays\ScheduledWorkdayResource;
use App\Filament\Resources\Usuarios\Users\Pages\ListUsers;
use App\Filament\Resources\Usuarios\Users\UserResource;
use App\Livewire\Navigation\Sidebar;
use App\Models\User;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function navigationUser(string $role): User
{
    app(UsuariosPermissionsSeeder::class)->run();
    app(ControlHorarioPermissionsSeeder::class)->run();
    app(IncidenciasPermissionsSeeder::class)->run();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('groups current administrative links in order with recurring shifts and dated coverages', function () {
    $this->actingAs(navigationUser('administrador'));
    $groups = collect(Filament::getNavigation());
    $personal = $groups->first(fn ($group) => $group->getLabel() === 'Personal');

    expect(collect($personal->getItems())->map->getLabel()->all())->toBe([
        'Turnos asignados', 'Coberturas por día', 'Retrasos y faltas', 'Permisos',
    ]);
    expect(collect($personal->getItems())->first()->getUrl())->toBe(AssignedShifts::getUrl());
    expect(collect($personal->getItems())->values()->get(1)->getUrl())->toBe(ShiftCoverages::getUrl());
    $repayments = $groups->first(fn ($group) => $group->getLabel() === 'Reposiciones de caja');
    expect(collect($repayments->getItems())->map->getLabel()->all())->toBe(['Faltantes', 'Reposiciones']);
    expect($groups->first(fn ($group) => $group->getLabel() === 'Administración'))->not->toBeNull();
    $this->get(UserResource::getUrl())->assertOk()->assertSee('pg-brand', false);
});

it('shows a cashier only permitted navigation without administrative groups', function () {
    $this->actingAs(navigationUser('cajera'));
    $groups = collect(Filament::getNavigation());
    $labels = $groups->flatMap(fn ($group) => $group->getItems())->map->getLabel()->all();

    expect($labels)->toContain('Mi perfil')->not->toContain('Usuarios', 'Jornadas programadas', 'Turnos asignados', 'Coberturas por día', 'Permisos', 'Faltantes');
    $this->get(EditOwnProfile::getUrl())->assertOk();
    $this->get(ScheduledWorkdayResource::getUrl())->assertForbidden();
    $this->get(AssignedShifts::getUrl())->assertForbidden();
    $this->get(ShiftCoverages::getUrl())->assertForbidden();
});

it('hides the profile shortcut and keeps the route forbidden when individually restricted', function () {
    $user = navigationUser('cajera');
    $user->restrictedPermissions()->attach(Permission::findByName('perfil.ver', 'web'));
    $this->actingAs($user->fresh());
    $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())->map->getLabel()->all();

    expect($labels)->not->toContain('Mi perfil');
    $this->get(EditOwnProfile::getUrl())->assertForbidden();
});

it('renders only authorized destinations in the custom sidebar for a cashier', function () {
    $this->actingAs(navigationUser('cajera'));

    Livewire::test(Sidebar::class)
        ->assertSee('Mi perfil')
        ->assertDontSee('Jornadas programadas')
        ->assertDontSee('Turnos asignados')
        ->assertDontSee('Coberturas por día')
        ->assertDontSee('Faltantes')
        ->assertDontSee('Usuarios');
});

it('keeps native table searching and sorting available inside the custom shell', function () {
    $this->actingAs(navigationUser('administrador'));
    $first = User::factory()->create(['name' => 'Ana Navarro']);
    $second = User::factory()->create(['name' => 'Beatriz Soto']);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$first, $second])
        ->searchTable('Ana Navarro')
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second])
        ->searchTable('')
        ->sortTable('name')
        ->assertCanSeeTableRecords([$first, $second], inOrder: true);
});

it('escapes the user name while retaining the native profile and logout menu', function () {
    $user = navigationUser('cajera');
    $user->update(['name' => '<img src=x onerror=alert(1)>']);
    $this->actingAs($user);

    Livewire::test(Sidebar::class)
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false)
        ->assertSee('logout', false);
});

it('keeps an accessible name on navigation links when their visual labels are hidden', function () {
    $this->actingAs(navigationUser('cajera'));

    Livewire::test(Sidebar::class)
        ->assertSee('aria-label="Mi perfil"', false);
});

it('keeps sidebar scroll storage scoped to the signed in user and panel', function () {
    $user = navigationUser('cajera');
    $this->actingAs($user);
    Livewire::test(Sidebar::class)->assertSee('data-sidebar-scroll', false)->assertSee('data-scroll-key="pg-sidebar-scroll:inicio:'.$user->id.'"', false);
});
