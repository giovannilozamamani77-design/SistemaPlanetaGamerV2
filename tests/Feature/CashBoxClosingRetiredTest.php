<?php

use App\Domain\Arqueos\Calculators\CashCountCalculator;
use App\Domain\Cajas\Actions\ConfigureCashBoxAction;
use App\Domain\Cajas\Actions\OpenCashBoxAction;
use App\Domain\Cajas\Actions\RecordQuickCashBoxCommissionAction;
use App\Domain\Cajas\Actions\StartCashBoxAccessAction;
use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Liquidaciones\Actions\InstallDemoLiquidationAccountAction;
use App\Domain\Liquidaciones\Actions\SetLiquidationRulesAction;
use App\Domain\Liquidaciones\Queries\ClosingLiquidationPreviewQuery;
use App\Filament\Pages\Cajas\CashBoxCatalog;
use App\Filament\Pages\Cajas\CashBoxClosingStart;
use App\Filament\Pages\Cajas\CashBoxOperations;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function c69OpenFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    config(['cajas.schedule_controls_enabled' => false]);
    test()->travelTo(CarbonImmutable::parse('2026-10-20 20:00', 'America/La_Paz'));
    test()->seed([UsuariosPermissionsSeeder::class, CajasPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    $user = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $user->assignRole('administrador');
    $box = CashBox::factory()->create();
    $accounts = [];
    foreach (CashBoxComponents::forType('BCP') as $code => $name) {
        $accounts[$code] = ['name' => $name, 'initial' => match ($code) {
            'bcp' => '15000', 'efectivo' => '2000', default => '3000'
        }];
    }
    app(ConfigureCashBoxAction::class)->execute($user, $box, ['accounts' => $accounts, 'base_capital' => '20000', 'shifts' => CashBoxComponents::defaultShifts(), 'expected_revision' => $box->revision, 'reason' => 'Configuracion ficticia de prueba', 'request_key' => (string) Str::uuid()]);
    $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => ['bcp' => '15000', 'efectivo' => '2000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    test()->actingAs($user);

    return [$user, $box, $session];
}

it('retires closing routes actions and recovery while keeping PIN opening and quick commissions', function () {
    [$user, $box, $session] = c69OpenFixture();
    $page = Livewire::withQueryParams(['cashBox' => $box->id])->test(CashBoxOperations::class)
        ->assertSee('Registrar comisión')->assertDontSee('Cerrar mi caja')->assertDontSee('Continuar cierre pendiente')->assertDontSee('Realizar arqueo');
    $page->set('quickServices.bill_count', '2')->call('registerQuickCommission', 'FACTURAS')->assertHasNoErrors();
    expect($session->fresh()->status)->toBe('ABIERTA')->and($session->fresh()->current_balances['efectivo'])->toBe(200100);
    $this->get('/inicio/cajas/'.$session->id.'/cierre')->assertNotFound();
    expect(method_exists(CashBoxOperations::class, 'registrarCierreAction'))->toBeFalse();
    expect(class_exists('App\\Domain\\Cajas\\Actions\\RecordCashBoxCloseAction'))->toBeFalse();
    $this->assertDatabaseCount('cash_box_close_drafts', 0);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('never recovers an old draft or previous closing query parameters in the operating panel', function () {
    [$user, $box, $session] = c69OpenFixture();
    DB::table('cash_box_close_drafts')->insert(['cash_box_session_id' => $session->id, 'user_id' => $user->id, 'request_key' => (string) Str::uuid(), 'status' => 'DRAFT', 'payload' => 'NO-MOSTRAR-CIERRE-ANTERIOR', 'version' => 15, 'created_at' => now(), 'updated_at' => now()]);
    Livewire::withQueryParams(['cashBox' => $box->id, 'pendingClose' => 999])->test(CashBoxOperations::class)
        ->assertSee('Registrar comisión')->assertDontSee('NO-MOSTRAR-CIERRE-ANTERIOR')->assertDontSee('Continuar cierre');
    Livewire::test(CashBoxCatalog::class)->assertDontSee('Cierre pendiente')->assertDontSee('Completa el cierre');
    expect(method_exists(CashBoxOperations::class, 'continuarLiquidacionAction'))->toBeFalse();
    expect(DB::table('cash_box_close_drafts')->value('version'))->toBe(15);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_operations', 0);
});

it('shows unavailable historical boxes without reopening or offering the removed closing flow', function () {
    [$user, $box, $session] = c69OpenFixture();
    $session->update(['status' => 'CIERRE_REGISTRADO']);
    Livewire::test(CashBoxCatalog::class)->assertSee('No disponible')->assertDontSee('Cierre pendiente')->assertDontSee('Continuar cierre');
    expect(fn () => app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid()))->toThrow(ValidationException::class);
    expect($session->fresh()->status)->toBe('CIERRE_REGISTRADO');
    $this->assertDatabaseCount('cash_box_close_drafts', 0);
});

it('opens only a fresh shift selection page for the owner without recovering or saving anything', function () {
    [$user, $box, $session] = c69OpenFixture();
    DB::table('cash_box_close_drafts')->insert(['cash_box_session_id' => $session->id, 'user_id' => $user->id, 'request_key' => (string) Str::uuid(), 'status' => 'DRAFT', 'payload' => 'CIERRE-ANTIGUO-NO-RECUPERAR', 'version' => 19, 'created_at' => now(), 'updated_at' => now()]);
    $before = $session->fresh()->getAttributes();
    $operations = Livewire::withQueryParams(['cashBox' => $box->id])->test(CashBoxOperations::class)->assertSee('Cerrar caja');
    $operations->callAction('iniciarCierre')->assertRedirect(CashBoxClosingStart::getUrl(['cashBoxSession' => $session->id]));
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->assertSee('Selecciona el turno')
        ->assertSee('Mañana')->assertSee('Tarde')->assertSee('08:00')->assertSee('20:00')
        ->assertDontSee('CIERRE-ANTIGUO-NO-RECUPERAR')->assertSet('period', null)
        ->set('period', 'tarde')->assertHasNoErrors()->assertSet('period', 'tarde');
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->assertSet('period', null)
        ->set('period', 'inventado')->assertHasErrors(['period']);
    $this->get(CashBoxClosingStart::getUrl(['cashBoxSession' => $session->id]))->assertOk();
    expect($session->fresh()->getAttributes())->toBe($before);
    expect(DB::table('cash_box_close_drafts')->value('version'))->toBe(19);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_commands', 0);
    $this->assertDatabaseCount('cash_box_operations', 0);
});

it('denies the new shift page to another cashier or admin and rejects a session no longer open', function (string $role) {
    [$owner, $box, $session] = c69OpenFixture();
    $other = User::factory()->create();
    $other->assignRole($role);
    $this->actingAs($other);
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->assertForbidden();
    $this->actingAs($owner);
    $session->update(['status' => 'CIERRE_REGISTRADO']);
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->assertForbidden();
    $this->assertDatabaseCount('cash_box_close_drafts', 0);
})->with(['cajera', 'administrador']);

it('goes from shift to live final balances without saving drafts or closing the session', function () {
    [$user, $box, $session] = c69OpenFixture();
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 2, 'request_key' => (string) Str::uuid()]);
    $before = $session->fresh()->getAttributes();
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])
        ->call('nextStep')->assertHasErrors(['period'])
        ->set('period', 'manana')->call('nextStep')->assertHasNoErrors()->assertSet('step', 2)->assertSee('Saldos finales');
    $page->set('balances.efectivo', '5000')->set('balances.bcp', '15020')->set('balances.yape', '')
        ->assertSee('20020.00')->assertSee('19.00')->assertSee('Sobrante');
    expect($page->instance()->preview['difference_cents'])->toBe(1900);
    $page->set('balances.bcp', '15001')->assertSee('Sin diferencia');
    expect($page->instance()->preview['difference_cents'])->toBe(0);
    $page->set('balances.bcp', '15000')->assertSee('Faltante');
    expect($page->instance()->preview['difference_cents'])->toBe(-100);
    $page->set('balances.efectivo', 'incorrecto')->assertSee('Revisa los importes');
    expect($page->instance()->preview['valid'])->toBeFalse();
    $page->call('previousStep')->assertSet('step', 1)->assertSet('balances.bcp', '15000');
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->assertSet('step', 1)->assertSet('period', null)->assertSet('balances.bcp', '');
    expect($session->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('cash_box_close_drafts', 0);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_commands', 1);
    $this->assertDatabaseCount('cash_box_operations', 1);
});

it('prepares BCP counters and the shift summary without recording any closing effects', function () {
    [$user, $box, $session] = c69OpenFixture();
    test()->travelTo(now('America/La_Paz')->setDate(2026, 10, 19)->setTime(12, 0));
    DB::table('cash_box_counter_readings')->insert(['cash_box_id' => $box->id, 'user_id' => $user->id, 'month' => '2026-10', 'reading' => 100, 'fixed_reading' => 50, 'delta' => 0, 'baseline' => true, 'created_at' => now()]);
    DB::table('cash_box_counter_readings')->insert(['cash_box_id' => $box->id, 'user_id' => $user->id, 'month' => '2026-10', 'reading' => 900, 'fixed_reading' => 900, 'delta' => 0, 'baseline' => false, 'voided_at' => now(), 'created_at' => now()]);
    $before = $session->fresh()->getAttributes();
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])
        ->set('period', 'tarde')->call('nextStep')->set('balances.efectivo', '5000')->set('balances.bcp', '15000')->call('nextStep')
        ->assertSet('step', 3)->assertSee('Transacciones generales')->assertSee('Transacciones fijas');
    $page->call('nextStep')->assertHasErrors(['readings.general', 'readings.fixed']);
    $page->set('readings.general', '190')->set('readings.fixed', '80');
    expect($page->instance()->counterPreview['counters']['general']['delta'])->toBe(90)
        ->and($page->instance()->counterPreview['counters']['fixed']['delta'])->toBe(30);
    $page->call('nextStep')->assertSet('step', 4)->assertSee('Resumen del cierre')->assertSee('Liquidación requerida')->assertSee('Total contado');
    $page->call('previousStep')->assertSet('step', 3)->call('previousStep')->assertSet('step', 2)->call('previousStep')->assertSet('step', 1)
        ->set('period', 'manana')->call('nextStep')->call('nextStep')->call('nextStep')->assertSee('Sin liquidación')->assertSet('step', 4);
    expect($session->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_close_drafts', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
    $this->assertDatabaseCount('cash_box_counter_readings', 2);
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->assertSet('step', 1)->assertSet('readings.general', '');
});

it('resets the monthly counter reference without reading another box or inventing a first delta', function () {
    [$user, $box, $session] = c69OpenFixture();
    test()->travelTo(now('America/La_Paz')->setDate(2026, 11, 1)->setTime(0, 1));
    DB::table('cash_box_counter_readings')->insert(['cash_box_id' => $box->id, 'user_id' => $user->id, 'month' => '2026-10', 'reading' => 900, 'fixed_reading' => null, 'delta' => 0, 'baseline' => true, 'created_at' => now()]);
    $other = CashBox::factory()->create();
    DB::table('cash_box_counter_readings')->insert(['cash_box_id' => $other->id, 'user_id' => $user->id, 'month' => '2026-11', 'reading' => 9000, 'fixed_reading' => 8000, 'delta' => 0, 'baseline' => true, 'created_at' => now()]);
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])
        ->set('period', 'manana')->call('nextStep')->call('nextStep')->assertSet('step', 3)->assertSee('Inicio del nuevo mes')->assertSee('Sin referencia')
        ->set('readings.general', '20')->set('readings.fixed', '5');
    expect($page->instance()->counterPreview['counters']['general']['delta'])->toBe(20)
        ->and($page->instance()->counterPreview['counters']['fixed']['delta'])->toBeNull();
    $page->set('readings.general', '-1')->call('nextStep')->assertHasErrors(['readings.general'])->assertSet('step', 3);
    $page->set('readings.general', '20')->call('nextStep')->assertHasNoErrors()->assertSee('Faltante')->assertSee('confirmación de Administración');
    $this->assertDatabaseCount('cash_box_counter_readings', 2);
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('shows the summary commission once and blocks invalid balances before leaving the balance step', function () {
    [$user, $box, $session] = c69OpenFixture();
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 2, 'request_key' => (string) Str::uuid()]);
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'tarde')->call('nextStep')
        ->set('balances.efectivo', '-1')->call('nextStep')->assertHasErrors(['balances'])->assertSet('step', 2)
        ->set('balances.efectivo', '5001')->set('balances.bcp', '15000')->call('nextStep')->assertHasNoErrors()
        ->set('readings.general', '190')->set('readings.fixed', '80')->call('nextStep')->assertSet('step', 4)->assertSee('Sin diferencia');
    expect($page->instance()->preview['total_cents'])->toBe(2000100)
        ->and($page->instance()->context['commission_total'])->toBe(100);
    $page->call('nextStep')->assertSet('step', 4);
    $page->set('period', 'inventado')->assertHasErrors(['period'])->assertSee('Revisa el turno');
    $page->set('period', 'tarde')->set('balances.efectivo', '-1')->assertSee('Revisa los saldos y los contadores');
    $this->assertDatabaseCount('cash_box_operations', 1);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_counter_readings', 0);
});

it('does not ask a non BCP box for BCP readings or load counter history', function () {
    [$user, $box, $session] = c69OpenFixture();
    /** Only the family discriminator changes; the preview must not use BCP counters. */
    $box->update(['type' => 'TIGO_MONEY']);
    DB::enableQueryLog();
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'manana')->call('nextStep')->call('nextStep')
        ->assertSet('step', 3)->assertSee('Esta caja no utiliza contadores BCP')->call('nextStep')->assertSet('step', 4)->assertHasNoErrors();
    $counterQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'cash_box_counter_readings'));
    DB::disableQueryLog();
    expect($counterQueries)->toHaveCount(0);
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('uses each latest non voided counter independently and authorizes every preview update', function () {
    [$user, $box, $session] = c69OpenFixture();
    test()->travelTo(now('America/La_Paz')->setDate(2026, 10, 19)->setTime(12, 0));
    foreach ([[100, 50], [120, null]] as [$reading, $fixed]) {
        DB::table('cash_box_counter_readings')->insert(['cash_box_id' => $box->id, 'user_id' => $user->id, 'month' => '2026-10', 'reading' => $reading, 'fixed_reading' => $fixed, 'delta' => 0, 'baseline' => true, 'created_at' => now()]);
    }
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'manana')->call('nextStep')->call('nextStep')
        ->set('readings.general', '130')->set('readings.fixed', '60');
    expect($page->instance()->counterPreview['counters']['general']['delta'])->toBe(10)
        ->and($page->instance()->counterPreview['counters']['fixed']['delta'])->toBe(10);
    $session->update(['status' => 'CIERRE_REGISTRADO']);
    $page->call('nextStep')->assertForbidden();
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('cancels preparation from any step and resumes commissions in the same still open box', function (int $step) {
    [$user, $box, $session] = c69OpenFixture();
    app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 2, 'request_key' => (string) Str::uuid()]);
    $before = $session->fresh()->getAttributes();
    $tables = ['cash_box_operations', 'cash_box_counter_readings', 'cash_box_closes', 'cash_box_close_drafts', 'cash_box_liquidations', 'cash_box_commands', 'cash_box_audits'];
    $snapshots = [];
    foreach ($tables as $table) {
        $snapshots[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->assertSee('Cancelar cierre');
    if ($step >= 2) {
        $page->set('period', 'tarde')->call('nextStep')->set('balances.efectivo', '5001')->set('balances.bcp', '15000');
    }
    if ($step >= 3) {
        $page->call('nextStep')->set('readings.general', '100')->set('readings.fixed', '50');
    }
    if ($step === 4) {
        $page->call('nextStep');
    }
    $page->assertSet('step', $step)->assertSee('Cancelar cierre');
    if ($step >= 2) {
        $page->set('balances.efectivo', '-1');
    }
    $page->call('cancelClosing')->assertHasNoErrors()->assertSet('step', 1)->assertSet('period', null)
        ->assertSet('balances.bcp', '')->assertSet('readings.general', '')
        ->assertRedirect(CashBoxOperations::getUrl(['cashBox' => $box->id]));
    $page->call('cancelClosing')->assertRedirect(CashBoxOperations::getUrl(['cashBox' => $box->id]));
    expect($session->fresh()->getAttributes())->toBe($before);
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($snapshots[$table]);
    }
    $operations = Livewire::withQueryParams(['cashBox' => $box->id])->test(CashBoxOperations::class)
        ->assertSee('Registrar comisión')->assertDontSee('Continuar cierre pendiente');
    expect($operations->instance()->workspace['session_id'])->toBe($session->id);
    $operations->set('quickServices.bill_count', '2')->call('registerQuickCommission', 'FACTURAS')->assertHasNoErrors();
    expect($session->fresh()->status)->toBe('ABIERTA')->and($session->fresh()->current_balances['efectivo'])->toBe(200200);
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->assertSet('step', 1)->assertSet('period', null)->assertSet('balances.bcp', '')->assertSet('readings.general', '');
    $this->assertDatabaseCount('cash_box_closes', 0);
})->with(['turno' => 1, 'saldos' => 2, 'operaciones' => 3, 'resumen' => 4]);

it('applies optional cash counting only on acceptance and discards unaccepted changes', function () {
    [$user, $box, $session] = c69OpenFixture();
    $before = $session->fresh()->getAttributes();
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'manana')->call('nextStep')
        ->assertSee('Realizar arqueo')->set('balances.efectivo', '123')->call('openCashCount')->assertSet('cashCountOpen', true)
        ->set('cashCountQuantities.b100', '3')->set('cashCountPacked.b100', '50')->set('cashCountQuantities.m5', '3')->set('cashCountPacked.m5', '1000');
    expect($page->instance()->cashCountPreview['snapshot']['total_cents'])->toBe(136500);
    $page->assertSet('balances.efectivo', '123')->call('applyCashCount')->assertHasNoErrors()->assertSet('balances.efectivo', '1365.00')->assertSet('cashCountOpen', false);
    $accepted = $page->instance()->acceptedCashCount;
    expect($accepted['quantities']['b100'])->toBe(3)->and($accepted['packed_amounts_cents']['m5'])->toBe(100000);
    $page->call('openCashCount')->set('cashCountQuantities.b100', '9')->call('dismissCashCount')->assertSet('balances.efectivo', '1365.00');
    expect($page->instance()->acceptedCashCount)->toBe($accepted);
    $page->call('openCashCount')->assertSet('cashCountQuantities.b100', '3')->call('dismissCashCount');
    $page->call('nextStep')->set('readings.general', '100')->set('readings.fixed', '50')->call('nextStep')->assertSee('Con arqueo');
    expect($session->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_close_drafts', 0);
    $this->assertDatabaseCount('cash_box_counter_readings', 0);
    $this->assertDatabaseCount('cash_box_operations', 0);
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->assertSet('acceptedCashCount', null);
});

it('keeps manual cash optional and rejects invalid count application without replacing accepted data', function () {
    [$user, $box, $session] = c69OpenFixture();
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id]);
    $page->call('openCashCount')->assertHasErrors(['cashCount'])->set('period', 'manana')->call('nextStep')
        ->call('applyCashCount')->assertHasErrors(['cashCount'])->set('balances.efectivo', '75');
    $page->call('openCashCount')->set('cashCountQuantities.b100', '3')->call('dismissCashCount')->assertSet('balances.efectivo', '75')->assertSet('acceptedCashCount', null);
    $page->call('openCashCount')->assertSet('cashCountQuantities.b100', '')->set('cashCountQuantities.b100', '3')->call('applyCashCount')->assertSet('balances.efectivo', '300.00');
    $accepted = $page->instance()->acceptedCashCount;
    $page->call('openCashCount')->set('cashCountQuantities.b100', '-1')->call('applyCashCount')->assertHasErrors(['cashCount'])->assertSet('cashCountOpen', true)->assertSet('balances.efectivo', '300.00');
    expect($page->instance()->acceptedCashCount)->toBe($accepted);
    $page->call('dismissCashCount')->set('balances.bcp', '15000');
    expect($page->instance()->acceptedCashCount)->toBe($accepted);
    $page->set('balances.efectivo', '301')->assertSet('acceptedCashCount', null)->call('nextStep')
        ->set('readings.general', '100')->set('readings.fixed', '50')->call('nextStep')->assertSee('No se tiene arqueo.');
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_close_drafts', 0);
});

it('discards the entire count when cancelling closing and never applies a count after the session stops being open', function () {
    [$user, $box, $session] = c69OpenFixture();
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'tarde')->call('nextStep')
        ->call('openCashCount')->set('cashCountQuantities.b100', '3')->call('applyCashCount')->call('openCashCount')->set('cashCountPacked.m5', '1000')
        ->call('cancelClosing')->assertSet('acceptedCashCount', null)->assertSet('cashCountOpen', false)->assertSet('cashCountQuantities', [])->assertSet('cashCountPacked', [])
        ->assertRedirect(CashBoxOperations::getUrl(['cashBox' => $box->id]));
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->assertSet('acceptedCashCount', null)->set('period', 'manana')->call('nextStep')->call('openCashCount');
    $session->update(['status' => 'CIERRE_REGISTRADO']);
    $page->call('applyCashCount')->assertForbidden();
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('shows an opening count only when it is actually recorded and never invents one for old manual openings', function () {
    [$user, $box, $session] = c69OpenFixture();
    $operations = Livewire::withQueryParams(['cashBox' => $box->id])->test(CashBoxOperations::class);
    expect($operations->instance()->detalleAperturaAction()->getModalContent()->render())->toContain('No se tiene arqueo.');
    $snapshot = app(CashCountCalculator::class)->calculate(['b100' => '20'], [])['snapshot'];
    $session->update(['comparison' => [...$session->comparison, 'count_details' => ['method' => 'ARQUEO', 'cash_count' => $snapshot]]]);
    $operations = Livewire::withQueryParams(['cashBox' => $box->id])->test(CashBoxOperations::class);
    expect($operations->instance()->detalleAperturaAction()->getModalContent()->render())->toContain('Con arqueo')->toContain('2000.00');
    $this->assertDatabaseCount('cash_box_closes', 0);
});

it('previews liquidation from the summary and returns without financial writes', function () {
    [$user, $box, $session] = c69OpenFixture();
    $before = $session->fresh()->getAttributes();
    $auditBefore = DB::table('cash_box_audits')->orderBy('id')->get()->toJson();
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])
        ->set('period', 'tarde')->call('nextStep')->set('balances.efectivo', '5000')->set('balances.bcp', '15050')
        ->call('nextStep')->set('readings.general', '100')->set('readings.fixed', '50')->call('nextStep')
        ->call('openLiquidationPreview')->assertHasNoErrors()->assertSet('liquidationPreviewOpen', true)
        ->assertSee('Volver sin registrar')->assertSee('Sin cuenta configurada');
    expect($page->instance()->liquidationPreview['calculation']['amount_cents'])->toBe(5000);
    expect($page->instance()->liquidationPreview['source'])->toBe('bcp');
    $page->call('dismissLiquidationPreview')->assertSet('liquidationPreviewOpen', false)->assertSet('step', 4)->assertSet('balances.bcp', '15050');
    $page->call('openLiquidationPreview')->call('dismissLiquidationPreview');
    expect($session->fresh()->getAttributes())->toBe($before);
    expect(DB::table('cash_box_audits')->orderBy('id')->get()->toJson())->toBe($auditBefore);
    foreach (['cash_box_closes', 'cash_box_liquidations', 'cash_box_close_drafts', 'cash_box_counter_readings', 'cash_box_operations'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $page->call('cancelClosing')->assertRedirect(CashBoxOperations::getUrl(['cashBox' => $box->id]));
});

it('uses the frozen origin and operating capital and renders its configured destination QR', function () {
    [$user, $box, $session] = c69OpenFixture();
    Storage::fake('local');
    $accountId = app(InstallDemoLiquidationAccountAction::class)->execute($user);
    app(SetLiquidationRulesAction::class)->execute($user, $box->id, [
        'source' => 'yape', 'source_reference' => '76537982', 'morning' => false, 'afternoon' => true,
        'expected_revision' => $box->fresh()->revision, 'reason' => 'Cambio ficticio para futuras aperturas', 'request_key' => (string) Str::uuid(),
    ]);
    $session->update(['operating_capital_cents' => 2500000]);
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->set('period', 'tarde')->call('nextStep')
        ->set('balances.efectivo', '5000')->set('balances.bcp', '20100')->call('nextStep')
        ->set('readings.general', '100')->set('readings.fixed', '50')->call('nextStep')->call('openLiquidationPreview')
        ->assertSee('No transferir')->assertSee(route('liquidaciones.cuenta-qr', ['account' => $accountId]), false);
    $quote = $page->instance()->liquidationPreview;
    expect($quote['source'])->toBe('bcp');
    expect($quote['calculation']['amount_cents'])->toBe(10000);
    expect($quote['calculation']['capital_after_cents'])->toBe(2500000);
    $page->call('previousStep')->assertSet('liquidationPreviewOpen', false)->assertSet('step', 3);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('explains zero excess shortage and insufficient origin without inventing a transfer', function ($cash, $bank, $status, $amount) {
    [$user, $box, $session] = c69OpenFixture();
    $quote = app(ClosingLiquidationPreviewQuery::class)->execute($user, $session->id, 'tarde', ['efectivo' => $cash, 'bcp' => $bank]);
    expect($quote['calculation']['status'])->toBe($status);
    expect($quote['calculation']['amount_cents'])->toBe($amount);
    expect($quote['calculation']['can_liquidate'])->toBeFalse();
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
})->with([
    ['5000', '15000', 'SIN_EXCEDENTE', 0],
    ['5000', '14900', 'FALTANTE', 0],
    ['21000', '50', 'ORIGEN_INSUFICIENTE', 105000],
]);

it('rejects liquidation preview for invalid steps shifts balances custody and another owner', function () {
    [$user, $box, $session] = c69OpenFixture();
    Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])->call('openLiquidationPreview')->assertHasErrors(['liquidation']);
    $query = app(ClosingLiquidationPreviewQuery::class);
    expect(fn () => $query->execute($user, $session->id, 'manana', ['bcp' => '20050']))->toThrow(ValidationException::class);
    expect(fn () => $query->execute($user, $session->id, 'tarde', ['bcp' => '-1']))->toThrow(ValidationException::class);
    $this->mock(ReservedCashBoxFunds::class, function ($mock) use ($box) {
        $mock->shouldReceive('forBox')->once()->with($box->id)->andReturn(['bcp' => 20000]);
    });
    expect(fn () => app(ClosingLiquidationPreviewQuery::class)->execute($user, $session->id, 'tarde', ['bcp' => '100']))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('administrador');
    expect(fn () => $query->execute($other, $session->id, 'tarde', ['bcp' => '20050']))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('cash_box_liquidations', 0);
});

it('initializes every cash count field before typing so live updates cannot replace sparse input arrays', function () {
    [$user, $box, $session] = c69OpenFixture();
    $page = Livewire::test(CashBoxClosingStart::class, ['cashBoxSession' => $session->id])
        ->set('period', 'manana')->call('nextStep')->call('openCashCount');
    $empty = array_fill_keys(array_keys(CashCountCalculator::DENOMINATIONS), '');
    expect($page->get('cashCountQuantities'))->toBe($empty)
        ->and($page->get('cashCountPacked'))->toBe($empty);
    foreach (['5', '50', '500', '5000'] as $value) {
        $page->set('cashCountQuantities.m010', $value)->assertSet('cashCountQuantities.m010', $value);
        expect(array_keys($page->get('cashCountQuantities')))->toBe(array_keys($empty));
    }
    $page->set('cashCountPacked.m010', '5000.03')->set('cashCountQuantities.b100', '10')
        ->call('applyCashCount')->assertSet('balances.efectivo', '6500.03');
    expect($page->get('acceptedCashCount')['quantities']['m010'])->toBe(5000)
        ->and($page->get('acceptedCashCount')['packed_amounts_cents']['m010'])->toBe(500003);
    $this->assertDatabaseCount('cash_box_closes', 0);
    $this->assertDatabaseCount('cash_box_close_drafts', 0);
});
