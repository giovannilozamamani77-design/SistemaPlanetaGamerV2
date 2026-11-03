<?php

use App\Domain\Cajas\Actions\ConfigureCashBoxAction;
use App\Domain\Cajas\Actions\OpenCashBoxAction;
use App\Domain\Cajas\Actions\StartCashBoxAccessAction;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxType;
use App\Filament\Pages\Cajas\CashBoxOperations;
use App\Filament\Resources\Cajas\CashBoxes\Pages\ListCashBoxes;
use App\Filament\Resources\Cajas\Types\Pages\ListCashBoxTypes;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function c5User(): User
{
    test()->seed([UsuariosPermissionsSeeder::class, CajasPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    $user = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $user->assignRole('administrador');
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    test()->actingAs($user);
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/La_Paz'));

    return $user;
}
function c5Data(CashBox $box, bool $declared = false): array
{
    $accounts = [];
    foreach (CashBoxComponents::forType('BCP') as $code => $name) {
        $accounts[$code] = ['name' => $name, 'initial' => $declared ? match ($code) {
            'efectivo' => '2000','bcp' => '15000',default => '3000'
        } : null];
    }

    return ['accounts' => $accounts, 'base_capital' => '20000', 'expected_revision' => $box->revision, 'reason' => 'Configuración inicial', 'request_key' => (string) Str::uuid(), 'shifts' => CashBoxComponents::defaultShifts()];
}
it('saves configuration without a second PIN or initial balances and counts money on first opening', function () {
    $user = c5User();
    $box = CashBox::factory()->create();
    $config = app(ConfigureCashBoxAction::class)->execute($user, $box, c5Data($box));
    expect($config->first_count_pending)->toBeTrue();
    $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    expect($session->opening_balances)->toBe(['bcp' => 1500000, 'efectivo' => 200000, 'yape' => 300000])->and($session->comparison['difference_cents'])->toBe(0);
    test()->assertDatabaseCount('cash_box_receipt_discrepancies', 0);
});
it('shows a clear persistent notification for general configuration errors', function () {
    $user = c5User();
    $box = CashBox::factory()->create();
    $data = c5Data($box, true);
    $data['pin'] = '1234';
    $data['shifts']['manana']['end'] = '15:00';
    Livewire::test(ListCashBoxes::class)->callTableAction('configurar', $box, data: $data)->assertHasActionErrors();
    Notification::assertNotified('No se pudo completar la acción');
    test()->assertDatabaseCount('cash_box_configurations', 0);
});
it('saves the actual configuration modal without another PIN or initial amounts', function () {
    $user = c5User();
    $box = CashBox::factory()->create();
    Livewire::test(ListCashBoxes::class)->callTableAction('configurar', $box, data: c5Data($box))->assertHasNoActionErrors();
    Notification::assertNotified('Configuración guardada');
    expect(CashBoxConfiguration::where('cash_box_id', $box->id)->firstOrFail()->first_count_pending)->toBeTrue();
});
it('uses default shifts when configuring through the domain without an explicit schedule', function () {
    $user = c5User();
    $box = CashBox::factory()->create();
    $data = c5Data($box);
    unset($data['shifts']);
    $config = app(ConfigureCashBoxAction::class)->execute($user, $box, $data);
    expect($config->shifts['manana']['start'])->toBe('08:00')->and($config->shifts['tarde']['end'])->toBe('20:00');
});
it('shows the first opening as pending count rather than a fabricated zero reference', function () {
    $user = c5User();
    $box = CashBox::factory()->create();
    app(ConfigureCashBoxAction::class)->execute($user, $box, c5Data($box));
    Livewire::test(CashBoxOperations::class)->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors()->assertSee('Pendiente de contar');
});

it('saves the configuration using only the fields generated when opening the modal', function () {
    c5User();
    $box = CashBox::factory()->create();
    Livewire::test(ListCashBoxes::class)->mountTableAction('configurar', $box)->callMountedTableAction()->assertHasNoActionErrors();
    test()->assertDatabaseCount('cash_box_configurations', 1);
});

it('keeps the generated request key through validation retries and renews it only on reopening', function () {
    c5User();
    $box = CashBox::factory()->create();
    $page = Livewire::test(ListCashBoxes::class)->mountTableAction('configurar', $box);
    $key = $page->get('mountedActions')[0]['data']['request_key'];
    expect(Str::isUuid($key))->toBeTrue();
    $page->set('mountedActions.0.data.shifts.manana.end', '15:00')->callMountedTableAction()->assertHasActionErrors();
    expect($page->get('mountedActions')[0]['data']['request_key'])->toBe($key);
    $page->set('mountedActions.0.data.shifts.manana.end', '14:00')->callMountedTableAction()->assertHasNoActionErrors();
    test()->assertDatabaseHas('cash_box_configurations', ['request_key' => $key]);
    $page->mountTableAction('configurar', $box->fresh());
    expect($page->get('mountedActions')[0]['data']['request_key'])->not->toBe($key);
});
it('generates the hidden request key when editing a box or a type without injected test data', function () {
    c5User();
    $box = CashBox::factory()->create();
    $page = Livewire::test(ListCashBoxes::class)->mountTableAction('edit', $box);
    expect(Str::isUuid($page->get('mountedActions')[0]['data']['request_key']))->toBeTrue();
    $type = CashBoxType::where('code', 'BCP')->firstOrFail();
    $types = Livewire::test(ListCashBoxTypes::class)->mountTableAction('editar', $type);
    expect(Str::isUuid($types->get('mountedActions')[0]['data']['request_key']))->toBeTrue();
});
