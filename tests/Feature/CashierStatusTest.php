<?php

use App\Filament\Pages\ControlHorario\CashierStatus;
use App\Models\User;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows cashier profiles and balances only to administration', function () {
    $this->seed([UsuariosPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['name' => 'Cajera de prueba']);
    $cashier->assignRole('cajera');
    $manager = User::factory()->create();
    $manager->assignRole('encargado');
    $this->actingAs($admin);
    Livewire::test(CashierStatus::class)->assertCanSeeTableRecords([$cashier, $manager])
        ->assertCanNotSeeTableRecords([$admin])
        ->assertSee('Sin jornada registrada')->assertSee('Crédito de tiempo');
    foreach ([$cashier, $manager] as $actor) {
        $this->actingAs($actor)->get(CashierStatus::getUrl())->assertForbidden();
    }
});
