<?php

use App\Domain\Cajas\Actions\ConfigureCashBoxAction;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\ControlHorario\Actions\SaveScheduledWorkdayAction;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Filament\Pages\Cajas\CashBoxOperations;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    config(['cajas.schedule_controls_enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-06 14:17:00', 'America/La_Paz'));
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
});

function c403Setup(): array
{
    app(UsuariosPermissionsSeeder::class)->run();
    app(ControlHorarioPermissionsSeeder::class)->run();
    app(CajasPermissionsSeeder::class)->run();
    $admin = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $cashier->assignRole('cajera');
    $box = CashBox::factory()->create(['type' => 'BCP']);
    app(ConfigureCashBoxAction::class)->execute($admin, $box, ['accounts' => ['bcp' => ['name' => 'BCP', 'initial' => '16000'], 'efectivo' => ['name' => 'Efectivo', 'initial' => '0'], 'yape' => ['name' => 'Yape', 'initial' => '0']], 'shifts' => CashBoxComponents::defaultShifts(), 'base_capital' => '16000', 'expected_revision' => $box->revision, 'pin' => '1234', 'reason' => 'Configuración inicial', 'request_key' => (string) Str::uuid()]);

    return [$admin, $cashier, $box->fresh()];
}

it('warns about missing assignment on opening and resuming without inventing a delay or blocking the box', function () {
    [$admin, $cashier, $box] = c403Setup();
    $this->actingAs($cashier);
    $page = Livewire::test(CashBoxOperations::class)->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])
        ->set('openingBalances', ['bcp' => '16000', 'efectivo' => '0', 'yape' => '0'])->call('confirmOpening')->assertHasNoErrors()->assertNotified('Control de horario: revisión necesaria');
    expect(CashBoxSession::sole()->status)->toBe('ABIERTA')->and(TimeIncident::count())->toBe(0);
    Livewire::test(CashBoxOperations::class)->call('resumeBox', $box->id)->assertNotified('Control de horario: revisión necesaria');
});

it('keeps the real delay notice when an afternoon workday is assigned', function () {
    [$admin, $cashier, $box] = c403Setup();
    app(SaveScheduledWorkdayAction::class)->execute($admin, ['user_id' => $cashier->id, 'work_date' => '2026-11-06', 'period' => 'tarde']);
    $this->actingAs($cashier);
    Livewire::test(CashBoxOperations::class)->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])
        ->set('openingBalances', ['bcp' => '16000', 'efectivo' => '0', 'yape' => '0'])->call('confirmOpening')->assertHasNoErrors()->assertActionMounted('lateOpeningNotice');
    expect(TimeIncident::where('kind', 'RETRASO')->sole()->real_minutes)->toBe(2);
    expect(array_sum(CashBoxSession::sole()->current_balances))->toBe(1600000);
});

it('does not warn about missing assignment when schedule controls are paused', function () {
    [$admin, $cashier, $box] = c403Setup();
    config(['cajas.schedule_controls_enabled' => false]);
    $this->actingAs($cashier);
    Livewire::test(CashBoxOperations::class)->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])
        ->set('openingBalances', ['bcp' => '16000', 'efectivo' => '0', 'yape' => '0'])->call('confirmOpening')->assertHasNoErrors()->assertNotNotified('Control de horario: revisión necesaria');
    expect(TimeIncident::count())->toBe(0);
});
