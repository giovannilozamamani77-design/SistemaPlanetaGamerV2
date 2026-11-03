<?php

use App\Domain\Cajas\Actions\SetCashBoxCommissionAccountAction;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxLiquidation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Liquidaciones\Actions\InstallDemoLiquidationAccountAction;
use App\Domain\Liquidaciones\Actions\SetLiquidationRulesAction;
use App\Domain\Liquidaciones\Queries\LiquidationAccountQuery;
use App\Filament\Pages\Liquidaciones\LiquidationSettings;
use App\Filament\Resources\Liquidaciones\History\Pages\ListLiquidations;
use App\Models\User;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function liquidationAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, CajasPermissionsSeeder::class]);
    $admin = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $admin->assignRole('administrador');

    return $admin;
}

it('versions the Yape destination idempotently without altering cash sessions or prior accounts', function () {
    $admin = liquidationAdmin();
    $data = ['holder_name' => 'Titular de prueba', 'destination' => '70000000', 'reason' => 'Configuración ficticia de prueba', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    $id = app(SetCashBoxCommissionAccountAction::class)->execute($admin, $data);
    expect(app(SetCashBoxCommissionAccountAction::class)->execute($admin, $data))->toBe($id);
    $this->assertDatabaseCount('cash_box_commission_accounts', 1);
    $this->assertDatabaseCount('cash_box_sessions', 0);
});

function liquidationBox(User $admin): array
{
    $box = CashBox::factory()->create(['created_by' => $admin->id]);
    $configuration = CashBoxConfiguration::create(['cash_box_id' => $box->id, 'created_by' => $admin->id, 'base_capital_cents' => 2000000,
        'accounts' => ['bcp' => ['name' => 'Cuenta BCP', 'category' => 'banco', 'initial_cents' => 1500000, 'reference' => null], 'yape' => ['name' => 'Yape operativo', 'category' => 'billetera', 'initial_cents' => 0, 'reference' => null], 'efectivo' => ['name' => 'Efectivo', 'category' => 'efectivo', 'initial_cents' => 500000]],
        'shifts' => CashBoxComponents::defaultShifts(), 'settlement_source' => 'bcp', 'first_count_pending' => false,
        'reason' => 'Configuración ficticia', 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'test'), 'created_at' => now()]);
    $session = CashBoxSession::create(['cash_box_id' => $box->id, 'user_id' => $admin->id, 'configuration_id' => $configuration->id, 'base_capital_cents' => 2000000, 'operating_capital_cents' => 2000000,
        'opening_balances' => ['bcp' => 1500000, 'efectivo' => 500000], 'current_balances' => ['bcp' => 1500000, 'efectivo' => 500000], 'expected_balances' => [], 'comparison' => [], 'opened_at' => now(), 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'test')]);

    return [$box, $configuration, $session];
}

it('installs a non payable demo once and never displaces a real configured destination', function () {
    $admin = liquidationAdmin();
    Storage::fake('local');
    app(SetCashBoxCommissionAccountAction::class)->execute($admin, ['holder_name' => 'Titular real ficticio', 'destination' => '70000000', 'reason' => 'Cuenta ficticia', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $action = app(InstallDemoLiquidationAccountAction::class);
    $id = $action->execute($admin);
    expect($action->execute($admin))->toBe($id);
    expect(app(LiquidationAccountQuery::class)->current($admin)->destination)->toBe('70000000');
    $this->assertDatabaseCount('cash_box_commission_accounts', 2);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    expect(fn () => $action->execute($cashier))->toThrow(AuthorizationException::class);
});

it('versions per box origins and turn rules without changing an open session or its frozen configuration', function () {
    $admin = liquidationAdmin();
    [$box, $old, $session] = liquidationBox($admin);
    $before = $session->fresh()->getAttributes();
    $data = ['source' => 'yape', 'source_reference' => '76537982', 'morning' => false, 'afternoon' => true, 'expected_revision' => $box->revision,
        'reason' => 'Liquidar por Yape en próximas aperturas', 'request_key' => (string) Str::uuid()];
    $action = app(SetLiquidationRulesAction::class);
    $new = $action->execute($admin, $box->id, $data);
    expect($new->id)->not->toBe($old->id)->and($new->settlement_source)->toBe('yape')
        ->and($old->fresh()->settlement_source)->toBe('bcp')->and($session->fresh()->getAttributes())->toBe($before);
    expect($action->execute($admin, $box->id, $data)->id)->toBe($new->id);
    $this->assertDatabaseCount('cash_box_audits', 1);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
    expect(fn () => $action->execute($admin, $box->id, [...$data, 'source' => 'efectivo', 'expected_revision' => $box->fresh()->revision, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
});

it('renders the history cards and settings for administration but denies cashier configuration', function () {
    $admin = liquidationAdmin();
    [$box] = liquidationBox($admin);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    Livewire::test(ListLiquidations::class)->assertSee('La base es el capital operativo')->assertSee('Todavía no hay liquidaciones registradas');
    Livewire::test(LiquidationSettings::class)->assertSee($box->name)->mountAction('configureBox', ['box_id' => $box->id])->assertActionDataSet(['source' => 'bcp']);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier);
    Livewire::test(LiquidationSettings::class)->assertForbidden();
});

function liquidationRecord(User $actor, $box, $session, string $period = 'tarde')
{
    $close = CashBoxClose::create(['cash_box_session_id' => $session->id, 'cash_box_id' => $box->id, 'user_id' => $actor->id,
        'period' => $period, 'shift_snapshot' => [], 'counted_balances' => [], 'real_balances' => [], 'expected_balances' => [], 'comparison' => [], 'difference_cents' => 0,
        'commission_cents' => 5000, 'capital_cents' => 2000000, 'requires_liquidation' => true, 'settlement_source' => 'bcp', 'created_at' => now()]);

    return CashBoxLiquidation::create(['cash_box_close_id' => $close->id, 'cash_box_id' => $box->id, 'user_id' => $actor->id,
        'theoretical_cents' => 5000, 'safe_cents' => 5000, 'amount_cents' => 5000, 'remaining_cents' => 0, 'source' => 'bcp', 'destination' => 'Yape de prueba',
        'operation_reference' => (string) Str::uuid(), 'created_at' => now()]);
}

it('paginates five real historical records and denies the liquidation history to cashiers', function () {
    $admin = liquidationAdmin();
    $records = [];
    foreach (range(1, 6) as $index) {
        [$box, $config, $session] = liquidationBox($admin);
        $records[] = liquidationRecord($admin, $box, $session, $index === 1 ? 'manana' : 'tarde');
    }
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    $page = Livewire::test(ListLiquidations::class)
        ->assertCanSeeTableRecords(array_slice($records, 1))->assertCanNotSeeTableRecords([$records[0]])->call('gotoPage', 2)->assertCanSeeTableRecords([$records[0]]);
    expect($page->instance()->getTable()->getPaginationPageOptions())->toBe([5, 10, 25, 50]);
    $page->set('tableRecordsPerPage', 10)->assertCanSeeTableRecords($records);
    $page->filterTable('cash_box_id', $records[0]->cash_box_id)->assertCanSeeTableRecords([$records[0]])->assertCanNotSeeTableRecords([$records[1]])->resetTableFilters();
    $page->filterTable('user_id', $admin->id)->assertCanSeeTableRecords($records)->resetTableFilters();
    $page->filterTable('period', 'manana')->assertCanSeeTableRecords([$records[0]])->assertCanNotSeeTableRecords([$records[1]]);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $this->actingAs($cashier);
    Livewire::test(ListLiquidations::class)->assertForbidden();
});

it('serves the QR only to authorized users and refuses modified or missing images', function () {
    $admin = liquidationAdmin();
    Storage::fake('local');
    $id = app(InstallDemoLiquidationAccountAction::class)->execute($admin);
    $this->actingAs($admin)->get(route('liquidaciones.cuenta-qr', ['account' => $id]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $path = DB::table('cash_box_commission_accounts')->where('id', $id)->value('qr_path');
    Storage::disk('local')->put($path, 'MODIFICADO');
    $this->get(route('liquidaciones.cuenta-qr', ['account' => $id]))->assertNotFound();
    $unprivileged = User::factory()->create();
    $this->actingAs($unprivileged)->get(route('liquidaciones.cuenta-qr', ['account' => $id]))->assertForbidden();
});

it('configures origins through the administrative modal and rejects stale or unauthorized changes', function () {
    $admin = liquidationAdmin();
    app(SetCashBoxCommissionAccountAction::class)->execute($admin, ['holder_name' => 'Titular ficticio', 'destination' => '70000000', 'reason' => 'Cuenta ficticia', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    [$box, $config, $session] = liquidationBox($admin);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    Livewire::test(LiquidationSettings::class)->mountAction('configureBox', ['box_id' => $box->id])
        ->setActionData(['source' => 'yape', 'source_reference' => '76537982', 'morning' => false, 'afternoon' => true, 'reason' => 'Destino de prueba'])
        ->callMountedAction()->assertHasNoActionErrors();
    expect($box->latestConfiguration()->first()->settlement_source)->toBe('yape')->and($session->fresh()->configuration_id)->toBe($config->id);
    $payload = ['source' => 'bcp', 'source_reference' => 'PRUEBA', 'morning' => false, 'afternoon' => true, 'reason' => 'Cambio de prueba', 'expected_revision' => 1, 'request_key' => (string) Str::uuid()];
    expect(fn () => app(SetLiquidationRulesAction::class)->execute($admin, $box->id, $payload))->toThrow(ValidationException::class);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    expect(fn () => app(SetLiquidationRulesAction::class)->execute($cashier, $box->id, $payload))->toThrow(AuthorizationException::class);
});

it('prepares demo origins only when references are empty and does not change open session data', function () {
    $admin = liquidationAdmin();
    [$box, $old, $session] = liquidationBox($admin);
    $before = $session->fresh()->getAttributes();
    Storage::fake('local');
    $this->artisan('cajas:install-liquidation-demo', ['--actor' => $admin->id, '--configure-examples' => true])->assertSuccessful();
    $this->artisan('cajas:install-liquidation-demo', ['--actor' => $admin->id, '--configure-examples' => true])->assertSuccessful();
    expect($session->fresh()->getAttributes())->toBe($before)->and($old->fresh()->accounts['bcp']['reference'])->toBeNull();
    expect($box->latestConfiguration()->first()->accounts['bcp']['reference'])->toBe('PRUEBA-BCP-'.$box->id);
    $this->assertDatabaseCount('cash_box_commission_accounts', 1);
    $this->assertDatabaseCount('cash_box_configurations', 2);
    $this->assertDatabaseCount('cash_box_audits', 1);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('keeps named destinations independent and assigns a destination for future sessions per box', function () {
    $admin = liquidationAdmin();
    $action = app(SetCashBoxCommissionAccountAction::class);
    $base = ['holder_name' => 'Titular ficticio', 'reason' => 'Cuenta ficticia de prueba', 'pin' => '1234'];
    $legacy = $action->execute($admin, [...$base, 'destination' => '70000000', 'request_key' => (string) Str::uuid()]);
    $data = [...$base, 'name' => 'Comisiones banco alternativo', 'destination' => '80000000', 'request_key' => (string) Str::uuid()];
    $other = $action->execute($admin, $data);
    expect($action->execute($admin, $data))->toBe($other);
    expect(app(LiquidationAccountQuery::class)->current($admin)->id)->toBe($legacy);
    $destination = DB::table('cash_box_commission_accounts')->find($other);
    expect($destination->name)->toBe('Comisiones banco alternativo');
    [$box, $old, $session] = liquidationBox($admin);
    $config = app(SetLiquidationRulesAction::class)->execute($admin, $box->id, ['source' => 'bcp', 'source_reference' => null,
        'destination_key' => $destination->account_key, 'morning' => false, 'afternoon' => true, 'expected_revision' => $box->revision,
        'reason' => 'Destino por caja', 'request_key' => (string) Str::uuid()]);
    expect($config->settlement_destination_key)->toBe($destination->account_key);
    expect($session->fresh()->configuration_id)->toBe($old->id);
    expect(app(LiquidationAccountQuery::class)->forConfiguration($admin, $old)->id)->toBe($legacy);
    expect(app(LiquidationAccountQuery::class)->forConfiguration($admin, $config)->id)->toBe($other);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('versions one named destination without overwriting its previous details or other accounts', function () {
    $admin = liquidationAdmin();
    $action = app(SetCashBoxCommissionAccountAction::class);
    $data = ['name' => 'Destino A', 'holder_name' => 'Titular anterior', 'destination' => '70000000', 'reason' => 'Cuenta ficticia', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    $oldId = $action->execute($admin, $data);
    $old = DB::table('cash_box_commission_accounts')->find($oldId);
    $edit = [...$data, 'account_id' => $oldId, 'holder_name' => 'Titular nuevo', 'request_key' => (string) Str::uuid()];
    $newId = $action->execute($admin, $edit);
    expect($action->execute($admin, $edit))->toBe($newId);
    expect(DB::table('cash_box_commission_accounts')->find($oldId)->holder_name)->toBe('Titular anterior');
    expect(DB::table('cash_box_commission_accounts')->find($newId)->account_key)->toBe($old->account_key);
    expect(fn () => $action->execute($admin, [...$edit, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(app(LiquidationAccountQuery::class)->catalog($admin)->pluck('id')->all())->toBe([$newId]);
});

it('rejects unavailable destinations and unauthorized catalog changes without writing configuration', function () {
    $admin = liquidationAdmin();
    [$box] = liquidationBox($admin);
    $data = ['source' => 'bcp', 'destination_key' => 'missing', 'morning' => false, 'afternoon' => true,
        'expected_revision' => $box->revision, 'reason' => 'Prueba de validación', 'request_key' => (string) Str::uuid()];
    expect(fn () => app(SetLiquidationRulesAction::class)->execute($admin, $box->id, $data))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_configurations', 1);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    expect(fn () => app(SetCashBoxCommissionAccountAction::class)->execute($cashier, []))->toThrow(AuthorizationException::class);
    expect(fn () => app(LiquidationAccountQuery::class)->catalog($cashier))->toThrow(AuthorizationException::class);
});

it('creates and edits destinations through the administrative modal and selects one per box', function () {
    $admin = liquidationAdmin();
    [$box] = liquidationBox($admin);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $this->actingAs($admin);
    $page = Livewire::test(LiquidationSettings::class)->mountAction('accountSettings')
        ->setActionData(['name' => 'Destino de prueba', 'holder_name' => 'Titular de prueba', 'destination' => '90000001', 'reason' => 'Alta ficticia', 'pin' => '1234'])
        ->callMountedAction()->assertHasNoActionErrors()->assertSee('Destino de prueba');
    $account = DB::table('cash_box_commission_accounts')->sole();
    $page->mountAction('configureBox', ['box_id' => $box->id])
        ->setActionData(['destination_key' => $account->account_key, 'reason' => 'Asignación de prueba'])
        ->callMountedAction()->assertHasNoActionErrors();
    expect($box->latestConfiguration()->first()->settlement_destination_key)->toBe($account->account_key);
    $page->mountAction('accountSettings', ['account_id' => $account->id])->assertActionDataSet(['name' => 'Destino de prueba'])
        ->setActionData(['holder_name' => 'Otro titular de prueba', 'reason' => 'Cambio ficticio', 'pin' => '1234'])
        ->callMountedAction()->assertHasNoActionErrors()->assertSee('Otro titular de prueba');
    expect(DB::table('cash_box_commission_accounts')->find($account->id)->holder_name)->toBe('Titular de prueba');
    $this->assertDatabaseCount('cash_box_commission_accounts', 2);
});

it('serves the configured destination QR privately and rejects a reused QR after changing its number', function () {
    $admin = liquidationAdmin();
    Storage::fake('local');
    $path = UploadedFile::fake()->image('destino.png')->store('cash-box-commission-qr/'.$admin->id, 'local');
    $data = ['name' => 'Destino con QR', 'holder_name' => 'Titular ficticio', 'destination' => '70000000', 'qr_path' => $path,
        'reason' => 'Alta ficticia', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    $id = app(SetCashBoxCommissionAccountAction::class)->execute($admin, $data);
    $this->actingAs($admin)->get(route('liquidaciones.cuenta-qr', ['account' => $id]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(fn () => app(SetCashBoxCommissionAccountAction::class)->execute($admin, [...$data, 'account_id' => $id,
        'destination' => '70000001', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_commission_accounts', 1);
});
