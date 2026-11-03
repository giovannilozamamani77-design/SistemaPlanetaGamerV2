<?php

use App\Domain\Cajas\Actions\ConfigureCashBoxAction;
use App\Domain\Cajas\Actions\DecideCashBoxCorrectionAction;
use App\Domain\Cajas\Actions\MarkCashBoxCorrectionReadAction;
use App\Domain\Cajas\Actions\OpenCashBoxAction;
use App\Domain\Cajas\Actions\RecordCashBoxOperationAction;
use App\Domain\Cajas\Actions\RecordQuickCashBoxCommissionAction;
use App\Domain\Cajas\Actions\RequestCashBoxCorrectionAction;
use App\Domain\Cajas\Actions\StartCashBoxAccessAction;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxCorrection;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Queries\CashBoxWorkspaceQuery;
use App\Domain\Cajas\Queries\ReceiptObservationsQuery;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Filament\Pages\Cajas\CashBoxCatalog;
use App\Filament\Pages\Cajas\CashBoxOperations;
use App\Filament\Resources\Cajas\CashBoxes\Pages\ListCashBoxes;
use App\Filament\Support\Cajas\CashBoxComponentIcon;
use App\Livewire\Cajas\CorrectionAlerts;
use App\Models\User;
use App\Support\Bolivianos;
use Carbon\CarbonImmutable;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/La_Paz'));
    $this->seed([UsuariosPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class, CajasPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
});

function c2uiUser(string $role = 'cajera'): User
{
    $user = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $user->assignRole($role);

    return $user;
}

function c2uiData(CashBox $box): array
{
    return ['accounts' => ['efectivo' => ['name' => 'Efectivo', 'initial' => '2000'], 'bcp' => ['name' => 'Cuenta BCP', 'initial' => '15000'],
        'yape' => ['name' => 'Yape', 'initial' => '3000']], 'shifts' => CashBoxComponents::defaultShifts(), 'base_capital' => '20000',
        'expected_revision' => $box->revision, 'reason' => 'Configuración inicial', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
}

function c2uiBox(): CashBox
{
    $box = CashBox::factory()->create();
    app(ConfigureCashBoxAction::class)->execute(c2uiUser('administrador'), $box, c2uiData($box));

    return $box->fresh();
}

it('configures accounts and shifts through the administrative modal', function () {
    $admin = c2uiUser('administrador');
    $this->actingAs($admin);
    $box = CashBox::factory()->create();
    Livewire::test(ListCashBoxes::class)->callTableAction('configurar', $box, data: c2uiData($box))->assertHasNoActionErrors();
    $this->assertDatabaseCount('cash_box_accounts', 3);
    $this->assertDatabaseCount('cash_box_configurations', 1);
});

it('shows cashiers the operation page without giving them configuration access', function () {
    $this->actingAs(c2uiUser());
    $this->get(CashBoxOperations::getUrl())->assertOk()->assertSee('Cajas');
    $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())->map->getLabel()->all();
    expect($labels)->toContain('Operación de cajas')->not->toContain('Configuración de cajas');
});

it('opens directly from preloaded inline balances after PIN without another modal', function () {
    $user = c2uiUser();
    $box = c2uiBox();
    $this->actingAs($user);
    $page = Livewire::test(CashBoxOperations::class)
        ->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])
        ->assertHasNoActionErrors()->assertSee('Saldos de apertura')->assertDontSee('Segunda comprobación')->assertSet('openingBalances.efectivo', '2000');
    $page->set('openingBalances', ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'])->call('confirmOpening')->assertHasNoActionErrors()->assertSee('Registrar comisión')->assertSet('selectedCashBoxId', $box->id);
    $page->call('confirmOpening')->assertHasNoErrors();
    $this->assertDatabaseCount('cash_box_sessions', 1);
    $this->assertDatabaseHas('cash_box_sessions', ['user_id' => $user->id, 'status' => 'ABIERTA']);
});

it('resumes the owners open session after reloading without repeating PIN', function () {
    $user = c2uiUser();
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $this->actingAs($user);
    $page = Livewire::test(CashBoxOperations::class)->assertSet('accessId', $access->id)->assertDontSee('Sesión #'.$session->id)->assertSee('Registrar comisión')->mountAction('detalleApertura');
    expect($page->instance()->getMountedAction()->getModalContent()->render())->toContain('15000.00', 'Saldo de apertura');
    $this->assertDatabaseCount('cash_box_sessions', 1);
});

it('shows a clear denial in the PIN modal for a foreign open box', function () {
    $user = c2uiUser();
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
    app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $this->actingAs(c2uiUser('administrador'));
    Livewire::test(CashBoxOperations::class)->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])
        ->assertHasActionErrors(['pin'])->assertSet('accessId', null)->assertDontSee('Saldos de apertura conservados');
    expect(CashBoxSession::sole()->user_id)->toBe($user->id);
});

it('shows invalid PIN errors without retaining an access context', function () {
    $box = c2uiBox();
    $this->actingAs(c2uiUser());
    Livewire::test(CashBoxOperations::class)->callTableAction('ingresar', $box, data: ['pin' => '9999', 'request_key' => (string) Str::uuid()])
        ->assertHasActionErrors(['pin'])->assertSet('accessId', null);
});

it('opens the catalog from the first Cajas menu item and keeps operations resumable without PIN', function () {
    $user = c2uiUser();
    $box = c2uiBox();
    $other = c2uiBox();
    $this->actingAs($user);
    $page = Livewire::test(CashBoxOperations::class)
        ->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])
        ->set('openingBalances', ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'])->call('confirmOpening')
        ->assertDontSee('Todas las cajas');
    $page = Livewire::test(CashBoxCatalog::class)->assertSet('accessId', null)->assertSee($other->name);
    $group = collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'Cajas');
    expect(collect($group->getItems())->first()->getLabel())->toBe('Cajas');
    $audits = DB::table('user_audits')->count();
    $page->callTableAction('ingresar', $box, data: [])->assertHasNoActionErrors()->assertSee('Registrar comisión');
    expect(DB::table('user_audits')->count())->toBe($audits);
    Livewire::test(CashBoxOperations::class)->assertSee('Registrar comisión')->assertSee($box->name);
    expect(CashBoxSession::sole()->status)->toBe('ABIERTA');
});

it('keeps operation-list queries bounded without reading balances or configurations per row', function () {
    $this->actingAs(c2uiUser());
    CashBox::factory()->count(20)->create();
    DB::enableQueryLog();
    $start = hrtime(true);
    try {
        Livewire::test(CashBoxOperations::class)->assertCountTableRecords(20);
        $queries = collect(DB::getQueryLog());
        $boxes = $queries->filter(fn (array $q): bool => str_contains($q['query'], '"cash_boxes"') && str_starts_with(mb_strtolower($q['query']), 'select'));
        $private = $queries->filter(fn (array $q): bool => str_contains($q['query'], '"cash_box_configurations"') || str_contains($q['query'], 'opening_balances') || str_contains($q['query'], 'current_balances'));
        $statusQueries = $queries->filter(fn (array $q): bool => str_contains($q['query'], '"cash_box_sessions"'));
        // One fixed resume lookup plus the catalog query; never one query per box.
        expect($statusQueries->count())->toBeLessThanOrEqual(2);
        expect($boxes->count())->toBeGreaterThan(0)->toBeLessThanOrEqual(3);
        expect($private)->toHaveCount(0);
        fwrite(STDOUT, "\nC2 list: ".$boxes->count().' box SELECTs; '.$private->count().' balance/config SELECTs; '.round((hrtime(true) - $start) / 1000000)." ms (test).\n");
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

it('shows receipt observations to administration without allowing cashier access to the review query', function () {
    $box = c2uiBox();
    $cashier = c2uiUser();
    $access = app(StartCashBoxAccessAction::class)->execute($cashier, $box, '1234', (string) Str::uuid());
    app(OpenCashBoxAction::class)->execute($cashier, $access->id, ['balances' => ['bcp' => '14950', 'efectivo' => '2000', 'yape' => '3000'],
        'reason' => 'Diferencia contada al recibir', 'request_key' => (string) Str::uuid()]);
    $this->actingAs(c2uiUser('administrador'));
    $page = Livewire::test(ListCashBoxes::class)->mountTableAction('recepciones', $box);
    $instance = $page->instance();
    $html = $instance->getSchema($instance->getMountedActionSchemaName())->toHtml();
    expect($html)->toContain('50.00', 'Diferencia contada al recibir');
    expect(fn () => app(ReceiptObservationsQuery::class)->execute($cashier, $box->id))
        ->toThrow(AuthorizationException::class);
});

it('shows all operational boxes by type with a state overview and no search or results controls', function () {
    $this->actingAs(c2uiUser());
    $boxes = CashBox::factory()->count(6)->create();
    CashBox::factory()->create(['type' => 'TIGO_MONEY']);
    $page = Livewire::test(CashBoxOperations::class)->assertSee('Agente BCP')->assertSee('Tigo Money')->assertSee('Cajas habilitadas')->assertSee('En operación')->assertSee('Cerradas')->assertSee('No disponibles')->assertDontSee('Cierre pendiente')
        ->assertDontSee('Selecciona tu caja')->assertDontSee('Buscar caja')->assertDontSee('Showing 1');
    foreach ($boxes as $box) {
        $page->assertSee($box->name);
    }
});
it('shows public occupation state without disclosing another cashiers name or balances', function () {
    $owner = c2uiUser();
    $owner->update(['name' => 'PRIVATE-OWNER-NAME']);
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($owner, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($owner, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $this->actingAs(c2uiUser());
    $page = Livewire::test(CashBoxOperations::class)->assertSee('Abierta')->assertDontSee('PRIVATE-OWNER-NAME')->assertDontSee('15000.00');
    expect($page->instance()->selection()['summary'])->toBe(['total' => 1, 'open' => 1, 'pending' => 0, 'closed' => 0]);
    $session->update(['status' => 'CIERRE_REGISTRADO']);
    $pending = Livewire::test(CashBoxOperations::class)->assertSee('No disponible')->assertDontSee('Cierre pendiente')->assertDontSee('PRIVATE-OWNER-NAME');
    expect($pending->instance()->selection()['summary'])->toBe(['total' => 1, 'open' => 0, 'pending' => 1, 'closed' => 0]);
});
it('escapes user supplied names in the cash box cards', function () {
    $this->actingAs(c2uiUser());
    CashBox::factory()->create(['name' => '<script>alert(1)</script>']);
    Livewire::test(CashBoxOperations::class)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
});

it('shows the configured main account reference without leaking other component references', function () {
    $box = c2uiBox();
    DB::table('cash_box_accounts')->where('cash_box_id', $box->id)->where('code', 'bcp')->update(['reference' => '001234567890']);
    DB::table('cash_box_accounts')->where('cash_box_id', $box->id)->where('code', 'yape')->update(['reference' => 'OTHER-COMPONENT-PRIVATE']);
    $tigo = CashBox::factory()->create(['type' => 'TIGO_MONEY']);
    DB::table('cash_box_accounts')->insert(['cash_box_id' => $tigo->id, 'code' => 'tigo_money', 'name' => 'Tigo Money', 'category' => 'tigo_money', 'reference' => '75302420', 'is_active' => true, 'position' => 1]);
    $this->actingAs(c2uiUser());

    Livewire::test(CashBoxOperations::class)->assertSee('001234567890')->assertSee('75302420')->assertDontSee('OTHER-COMPONENT-PRIVATE');

    DB::table('cash_box_accounts')->where('cash_box_id', $tigo->id)->update(['is_active' => false]);
    Livewire::test(CashBoxOperations::class)->assertDontSee('75302420')->assertSee('Sin número configurado');
});

it('paginates all session operations five at a time while totaling every commission', function () {
    $user = c2uiUser();
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    for ($i = 0; $i < 7; $i++) {
        app(RecordCashBoxOperationAction::class)->execute($user, $session->id, ['amount' => '0', 'fee_type' => 'FACTURAS', 'bill_count' => 1, 'fee_component' => 'efectivo', 'request_key' => (string) Str::uuid()]);
    }
    $this->actingAs($user);
    $page = Livewire::test(CashBoxOperations::class)->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])->assertHasNoActionErrors()->assertSee('Operaciones de esta sesión')->assertSee('Ver anteriores')->assertSeeHtml('wire:click="olderOperations"');
    $workspace = $page->instance()->workspace;
    expect($workspace['operations'])->toHaveCount(5);
    expect($workspace['commission_total'])->toBe(350);
    expect($workspace['opening_total'])->toBe(2000000);
    expect($workspace['expected_total'])->toBe(2000350);
    expect(array_column($workspace['operations'], 'id'))->toBe(CashBoxOperation::latest('id')->limit(5)->pluck('id')->all());
    $page->call('olderOperations')->assertHasNoErrors();
    $older = $page->instance()->workspace;
    expect($older['operations'])->toHaveCount(2)
        ->and($older['operations_page']['has_next'])->toBeFalse()
        ->and($older['commission_total'])->toBe(350)
        ->and(array_column($older['operations'], 'id'))->toBe(CashBoxOperation::orderBy('id')->pluck('id')->reverse()->values()->slice(5)->values()->all());
    $page->call('olderOperations');
    expect(array_column($page->instance()->workspace['operations'], 'id'))->toBe(array_column($older['operations'], 'id'));
    $page->mountAction('solicitarCorreccion', ['operation_id' => $older['operations'][0]['id']])->assertActionDataSet(['operation_id' => $older['operations'][0]['id']]);
    $page->unmountAction()->call('newerOperations')->assertHasNoErrors();
    expect(array_column($page->instance()->workspace['operations'], 'id'))->toBe(array_column($workspace['operations'], 'id'));
    $page->call('olderOperations');
    $page->set('quickServices.bill_count', '2')->call('registerQuickCommission', 'FACTURAS')->assertHasNoErrors();
    expect($page->instance()->workspace['commission_total'])->toBe(450)
        ->and($page->instance()->workspace['operations_page']['page'])->toBe(1)
        ->and($page->instance()->workspace['operations'][0]['id'])->toBe(CashBoxOperation::latest('id')->value('id'));
    expect(fn () => app(CashBoxWorkspaceQuery::class)->execute(c2uiUser(), $access->id, 2))->toThrow(AuthorizationException::class);
    $page->mountAction('registrarServicios')->assertActionDataSet(['fee_type' => 'FACTURAS']);
    $page->unmountAction()->mountAction('registrarAch')->assertActionDataSet(['fee_type' => 'ACH']);
});

it('records commissions directly from both quick cards and refreshes session balances', function () {
    $user = c2uiUser();
    $box = c2uiBox();
    $this->actingAs($user);
    $page = Livewire::test(CashBoxOperations::class)
        ->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])
        ->set('openingBalances', ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'])->call('confirmOpening');
    $key = $page->get('quickServicesKey');
    $page->set('quickServices.bill_count', '3')->call('registerQuickCommission', 'FACTURAS')->assertHasNoErrors()->assertSet('quickServices.bill_count', '');
    expect($page->get('quickServicesKey'))->not->toBe($key);
    $page->set('quickAch.amount', '4000')->set('quickAch.movement', 'RETIRO')->call('registerQuickCommission', 'ACH')->assertHasNoErrors();
    expect(CashBoxSession::sole()->current_balances['efectivo'])->toBe(204150);
    expect($page->instance()->workspace['commission_total'])->toBe(4150);
    $this->assertDatabaseCount('cash_box_operations', 2);
});

it('keeps invalid quick commissions unwritten and shows errors beside the card', function () {
    $user = c2uiUser();
    $box = c2uiBox();
    $this->actingAs($user);
    $page = Livewire::test(CashBoxOperations::class)
        ->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])
        ->set('openingBalances', ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'])->call('confirmOpening');
    $key = $page->get('quickServicesKey');
    $page->set('quickServices.bill_count', '0')->call('registerQuickCommission', 'FACTURAS')->assertHasErrors(['quickServices.bill_count']);
    expect($page->get('quickServicesKey'))->toBe($key);
    $this->assertDatabaseCount('cash_box_operations', 0);
    $page->set('quickServices.bill_count', '6')->call('registerQuickCommission', 'FACTURAS')->assertHasNoErrors();
    $this->assertDatabaseCount('cash_box_operations', 1);
    $page->callAction('salirDeVista')->call('registerQuickCommission', 'FACTURAS')->assertForbidden();
    $this->assertDatabaseCount('cash_box_operations', 1);
});

it('records only cash commission with informative principal, manual override and idempotent replay', function () {
    $user = c2uiUser();
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $action = app(RecordQuickCashBoxCommissionAction::class);
    $data = ['amount' => '100000', 'movement' => 'DEPOSITO', 'fee_type' => 'ACH', 'manual_fee' => '7.50', 'request_key' => (string) Str::uuid()];
    $first = $action->execute($user, $session->id, $data);
    $retry = $action->execute($user, $session->id, $data);
    expect($retry->id)->toBe($first->id);
    expect($first->commission_only)->toBeTrue();
    expect($first->amount_cents)->toBe(10000000);
    expect($first->movement)->toBe('DEPOSITO');
    expect($first->suggested_fee_cents)->toBe(100000);
    expect($first->fee_cents)->toBe(750);
    expect($session->fresh()->current_balances)->toBe(['bcp' => 1500000, 'efectivo' => 200750, 'yape' => 300000]);
    expect(fn () => $action->execute($user, $session->id, [...$data, 'manual_fee' => '8']))->toThrow(ValidationException::class);
    expect(fn () => $action->execute(c2uiUser('administrador'), $session->id, [...$data, 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    $services = $action->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 7, 'manual_fee' => '2', 'request_key' => (string) Str::uuid()]);
    expect($services->fee_cents)->toBe(200)->and($services->suggested_fee_cents)->toBe(350);
    $session->update(['status' => 'CIERRE_REGISTRADO']);
    expect(fn () => $action->execute($user, $session->id, [...$data, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('cash_box_operations', 2);
});

it('switches between owned boxes without PIN or new attendance and denies another owner', function () {
    $owner = c2uiUser();
    $boxes = [c2uiBox(), c2uiBox()];
    $accesses = [];
    foreach ($boxes as $box) {
        $a = app(StartCashBoxAccessAction::class)->execute($owner, $box, '1234', (string) Str::uuid());
        app(OpenCashBoxAction::class)->execute($owner, $a->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
        $accesses[] = $a;
    }
    $before = DB::table('user_audits')->count();
    $this->actingAs($owner);
    $page = Livewire::test(CashBoxOperations::class)->call('resumeBox', $boxes[0]->id)->assertSet('accessId', $accesses[0]->id)
        ->call('resumeBox', $boxes[1]->id)->assertSet('accessId', $accesses[1]->id);
    Livewire::test(CashBoxOperations::class)->assertSet('accessId', $accesses[1]->id);
    expect(DB::table('user_audits')->count())->toBe($before);
    $this->actingAs(c2uiUser('administrador'));
    Livewire::test(CashBoxOperations::class)->assertSet('accessId', null)->call('resumeBox', $boxes[0]->id)->assertForbidden();
});

it('shows the configured BCP account instead of session metadata and uses Spanish fund actions', function () {
    $box = CashBox::factory()->create();
    $data = c2uiData($box);
    $data['accounts']['bcp']['reference'] = '20152250448312';
    app(ConfigureCashBoxAction::class)->execute(c2uiUser('administrador'), $box, $data);
    $user = c2uiUser();
    $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $this->actingAs($user);
    $page = Livewire::test(CashBoxOperations::class)->assertSee('20152250448312')->assertDontSee('Sesión #')->assertSee('Agregar fondos')->assertSee('Retirar fondos');
    $page->mountAction('depositoTemporal');
    $action = $page->instance()->getMountedAction();
    expect($action->getModalSubmitAction()->getLabel())->toBe('Agregar fondos');
    expect($action->getModalCancelAction()->getLabel())->toBe('Cancelar');
    expect($action->getModalDescription())->toContain('capital operativo', 'comisión', 'sobrante');
    $page->fillForm(['component' => 'efectivo', 'amount' => '100', 'reason' => 'Dinero recibido de Administración', 'occurred' => true, 'request_key' => (string) Str::uuid()])->callMountedAction()->assertHasNoActionErrors();
    expect($session->fresh()->operating_capital_cents)->toBe(2010000);
    expect(DB::table('cash_box_operations')->count())->toBe(0);
    $page->mountAction('retiroTemporal');
    expect($page->instance()->getMountedAction()->getModalSubmitAction()->getLabel())->toBe('Registrar retiro');
    $page->fillForm(['component' => 'efectivo', 'amount' => '50', 'reason' => 'Dinero entregado a Administración', 'occurred' => true, 'request_key' => (string) Str::uuid()])->callMountedAction()->assertHasNoActionErrors();
    expect($session->fresh()->operating_capital_cents)->toBe(2005000);
    expect(DB::table('cash_box_operations')->count())->toBe(0);
});
it('omits the account label when no BCP reference is configured', function () {
    $box = c2uiBox();
    $user = c2uiUser();
    $a = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
    app(OpenCashBoxAction::class)->execute($user, $a->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $this->actingAs($user);
    Livewire::test(CashBoxOperations::class)->assertDontSee('Cuenta BCP:')->assertDontSee('Sesión #');
});

it('renders component logos as image URLs through Filament instead of treating HTML as a path', function () {
    foreach (['bcp' => 'bcp.png', 'efectivo' => 'efectivo-v2.png', 'yape' => 'yape.png', 'tigo_money' => 'tigo-money.png', 'entel' => 'entel-pdv.png', 'viva' => 'viva.jpg'] as $code => $file) {
        $icon = CashBoxComponentIcon::for($code, ['name' => $code, 'category' => 'recarga']);
        $html = Blade::render('<x-filament::icon :icon="$icon" />', ['icon' => $icon]);
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $images = $document->getElementsByTagName('img');
        expect($images->length)->toBe(1);
        expect($images->item(0)->getAttribute('src'))->toBe(asset('images/cajas/components/'.$file));
        expect(is_file(public_path('images/cajas/components/'.$file)))->toBeTrue();
    }
});

it('requests correction from a selected operation without deleting or changing its commission', function () {
    $user = c2uiUser();
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $op = app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 2, 'request_key' => (string) Str::uuid()]);
    $this->actingAs($user);
    $page = Livewire::test(CashBoxOperations::class)->assertSee('Acciones')
        ->mountAction('solicitarCorreccion', arguments: ['operation_id' => $op->id])
        ->assertActionDataSet(['operation_id' => $op->id]);
    $key = $page->get('mountedActions.0.data.request_key');
    $page->fillForm(['reason' => 'Se registraron dos facturas por error'])->callMountedAction()->assertHasNoActionErrors()->assertSee('Corrección pendiente');
    expect($op->fresh()->status)->toBe('VIGENTE');
    expect($session->fresh()->current_balances['efectivo'])->toBe(200100);
    $this->assertDatabaseCount('cash_box_operations', 1);
    $this->assertDatabaseCount('cash_box_corrections', 1);
    app(RequestCashBoxCorrectionAction::class)->execute($user, $op, ['operation_id' => $op->id, 'reason' => 'Se registraron dos facturas por error', 'request_key' => $key]);
    $this->assertDatabaseCount('cash_box_corrections', 1);
    $correction = CashBoxCorrection::sole();
    app(DecideCashBoxCorrectionAction::class)->execute(c2uiUser('administrador'), $correction, ['decision' => 'APROBADA', 'reason' => 'Error comprobado', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    expect($op->fresh()->status)->toBe('ANULADA');
    expect($op->fresh()->fee_cents)->toBe(100);
    expect($session->fresh()->current_balances['efectivo'])->toBe(200000);
    $this->assertDatabaseCount('cash_box_operations', 1);
});

it('rejects a row correction targeting an operation outside the active box', function () {
    $user = c2uiUser();
    $boxes = [c2uiBox(), c2uiBox()];
    foreach ($boxes as $box) {
        $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
        $session = app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    }
    $foreignOperation = app(RecordQuickCashBoxCommissionAction::class)->execute($user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    $this->actingAs($user);
    Livewire::test(CashBoxOperations::class)->call('resumeBox', $boxes[0]->id)
        ->mountAction('solicitarCorreccion', arguments: ['operation_id' => $foreignOperation->id])
        ->fillForm(['reason' => 'Intento de otra caja'])->callMountedAction()->assertHasActionErrors(['operation_id']);
    $this->assertDatabaseCount('cash_box_corrections', 0);
    $this->assertDatabaseCount('cash_box_operations', 1);
});

it('shows administrative feedback and completes returned corrections without losing earlier reasons', function () {
    $cashier = c2uiUser();
    $admin = c2uiUser('administrador');
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($cashier, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($cashier, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $operation = app(RecordQuickCashBoxCommissionAction::class)->execute($cashier, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 2, 'request_key' => (string) Str::uuid()]);
    $request = app(RequestCashBoxCorrectionAction::class)->execute($cashier, $operation, ['reason' => 'Solicitud original', 'request_key' => (string) Str::uuid()]);
    $date = $request->created_at;
    app(DecideCashBoxCorrectionAction::class)->execute($admin, $request, ['decision' => 'DEVUELTA', 'reason' => 'Indica el numero de factura', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    // More recent operations must not hide a returned case from the cashier.
    foreach (range(1, 6) as $n) {
        app(RecordQuickCashBoxCommissionAction::class)->execute($cashier, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    }
    $this->actingAs($cashier);
    $page = Livewire::test(CashBoxOperations::class)->assertDontSee('Indica el numero de factura')->mountAction('historialSolicitudes');
    expect($page->instance()->getMountedAction()->getModalContent()->render())->toContain('Indica el numero de factura', 'Completar solicitud');
    $page->call('replaceMountedAction', 'solicitarCorreccion', ['operation_id' => $operation->id])->assertActionDataSet(['reason' => 'Solicitud original']);
    expect($page->instance()->getMountedAction()->getModalContent()->render())->toContain('Indica el numero de factura', 'Solicitud original');
    $payload = ['operation_id' => $operation->id, 'reason' => 'Factura 123, se conto dos veces', 'request_key' => $page->get('mountedActions.0.data.request_key')];
    $page->fillForm($payload)->callMountedAction()->assertHasNoActionErrors();
    app(RequestCashBoxCorrectionAction::class)->execute($cashier, $operation, $payload);
    expect($request->fresh()->status)->toBe('PENDIENTE');
    expect($request->fresh()->created_at->equalTo($date))->toBeTrue();
    app(DecideCashBoxCorrectionAction::class)->execute($admin, $request, ['decision' => 'RECHAZADA', 'reason' => 'La factura era correcta', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $history = $request->fresh()->historyEntries();
    expect(array_column($history, 'reason'))->toBe(['Solicitud original', 'Indica el numero de factura', 'Factura 123, se conto dos veces', 'La factura era correcta']);
    expect($operation->fresh()->status)->toBe('VIGENTE');
    expect($session->fresh()->current_balances['efectivo'])->toBe(200400);
    $this->assertDatabaseCount('cash_box_corrections', 1);
    $view = Livewire::test(CashBoxOperations::class)->assertDontSee('La factura era correcta')->mountAction('historialSolicitudes');
    expect($view->instance()->getMountedAction()->getModalContent()->render())->toContain('La factura era correcta');
    $view->call('replaceMountedAction', 'respuestaCorreccion', ['operation_id' => $operation->id]);
    expect($view->instance()->getMountedAction()->getModalSubmitAction())->toBeNull();
    expect($view->instance()->getMountedAction()->getModalContent()->render())->toContain('Solicitud original', 'Indica el numero de factura', 'Factura 123, se conto dos veces', 'La factura era correcta');
});

it('rolls back a completed correction and its history on interruption and safely retries', function () {
    $cashier = c2uiUser();
    $admin = c2uiUser('administrador');
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($cashier, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($cashier, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $operation = app(RecordQuickCashBoxCommissionAction::class)->execute($cashier, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    $request = app(RequestCashBoxCorrectionAction::class)->execute($cashier, $operation, ['reason' => 'Motivo inicial', 'request_key' => (string) Str::uuid()]);
    $decision = ['decision' => 'DEVUELTA', 'reason' => 'Completar numero', 'pin' => '1234', 'request_key' => (string) Str::uuid()];
    app(DecideCashBoxCorrectionAction::class)->execute($admin, $request, $decision);
    app(DecideCashBoxCorrectionAction::class)->execute($admin, $request, $decision);
    expect($request->fresh()->historyEntries())->toHaveCount(2);
    $before = DB::table('cash_box_commands')->count();
    $data = ['reason' => 'Motivo completado numero 10', 'request_key' => (string) Str::uuid()];
    $event = 'eloquent.saved: '.CashBoxCorrection::class;
    $interrupt = true;
    Event::listen($event, function () use (&$interrupt): void {
        if ($interrupt) {
            $interrupt = false;
            throw new RuntimeException('Interrupcion simulada');
        }
    });
    try {
        expect(fn () => app(RequestCashBoxCorrectionAction::class)->execute($cashier, $operation, $data))->toThrow(RuntimeException::class);
        expect($request->fresh()->status)->toBe('DEVUELTA');
        expect($request->fresh()->historyEntries())->toHaveCount(2);
        expect(DB::table('cash_box_commands')->count())->toBe($before);
        app(RequestCashBoxCorrectionAction::class)->execute($cashier, $operation, $data);
        app(RequestCashBoxCorrectionAction::class)->execute($cashier, $operation, $data);
        expect($request->fresh()->historyEntries())->toHaveCount(3);
        expect($request->fresh()->status)->toBe('PENDIENTE');
        expect($session->fresh()->current_balances['efectivo'])->toBe(200050);
    } finally {
        Event::forget($event);
    }
});

it('preserves the persisted legacy reasons when completing a returned correction', function () {
    $cashier = c2uiUser();
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($cashier, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($cashier, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $operation = app(RecordQuickCashBoxCommissionAction::class)->execute($cashier, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    $request = app(RequestCashBoxCorrectionAction::class)->execute($cashier, $operation, ['reason' => 'Motivo legado', 'request_key' => (string) Str::uuid()]);
    app(DecideCashBoxCorrectionAction::class)->execute(c2uiUser('administrador'), $request, ['decision' => 'DEVUELTA', 'reason' => 'Respuesta anterior', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $request->fresh()->update(['history' => null]);
    app(RequestCashBoxCorrectionAction::class)->execute($cashier, $operation, ['reason' => 'Ampliacion nueva', 'request_key' => (string) Str::uuid()]);
    expect(array_column($request->fresh()->historyEntries(), 'reason'))->toBe(['Motivo legado', 'Respuesta anterior', 'Ampliacion nueva']);
    expect($request->fresh()->historyEntries()[0]['legacy'])->toBeTrue();
    $this->actingAs(c2uiUser());
    Livewire::test(CashBoxOperations::class)->assertDontSee('Respuesta anterior');
});

it('highlights unread responses and counts returned corrections until completed', function () {
    $cashier = c2uiUser();
    $admin = c2uiUser('administrador');
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($cashier, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($cashier, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $operation = app(RecordQuickCashBoxCommissionAction::class)->execute($cashier, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    $request = app(RequestCashBoxCorrectionAction::class)->execute($cashier, $operation, ['reason' => 'Revisar factura', 'request_key' => (string) Str::uuid()]);
    app(DecideCashBoxCorrectionAction::class)->execute($admin, $request, ['decision' => 'DEVUELTA', 'reason' => 'Completa la factura', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $this->actingAs($cashier);
    $page = Livewire::test(CashBoxOperations::class);
    expect($page->instance()->workspace['correction_alerts'])->toBe(['unread' => 1, 'returned' => 1]);
    expect($page->instance()->historialSolicitudesAction()->getColor())->toBe('primary');
    expect($page->instance()->historialSolicitudesAction()->getBadge())->toBe('1');
    $page->mountAction('historialSolicitudes');
    expect($request->fresh()->viewed_response_version)->toBe(0);
    $page->call('replaceMountedAction', 'respuestaCorreccion', ['operation_id' => $operation->id]);
    expect($request->fresh()->viewed_response_version)->toBe(1);
    expect($page->instance()->workspace['correction_alerts'])->toBe(['unread' => 0, 'returned' => 1]);
    expect($page->instance()->historialSolicitudesAction()->getColor())->toBe('warning');
    app(DecideCashBoxCorrectionAction::class)->execute($admin, $request, ['decision' => 'DEVUELTA', 'reason' => 'Nueva respuesta en el mismo segundo', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    app(MarkCashBoxCorrectionReadAction::class)->execute($cashier, $request->fresh(), 1);
    expect($request->fresh()->response_version)->toBe(2);
    expect($request->fresh()->viewed_response_version)->toBe(1);
    $page->call('replaceMountedAction', 'solicitarCorreccion', ['operation_id' => $operation->id])->fillForm(['reason' => 'Factura numero 123'])->callMountedAction()->assertHasNoActionErrors();
    expect($request->fresh()->viewed_response_version)->toBe(2);
    expect($page->instance()->workspace['correction_alerts'])->toBe(['unread' => 0, 'returned' => 0]);
    expect($page->instance()->historialSolicitudesAction()->getBadge())->toBeNull();
    expect($session->fresh()->current_balances['efectivo'])->toBe(200050);
});

it('counts all returned requests beyond the five visible responses and prioritizes them', function () {
    $cashier = c2uiUser();
    $admin = c2uiUser('administrador');
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($cashier, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($cashier, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    foreach (range(1, 7) as $number) {
        $operation = app(RecordQuickCashBoxCommissionAction::class)->execute($cashier, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
        $request = app(RequestCashBoxCorrectionAction::class)->execute($cashier, $operation, ['reason' => 'Solicitud numero '.$number, 'request_key' => (string) Str::uuid()]);
        app(DecideCashBoxCorrectionAction::class)->execute($admin, $request, ['decision' => $number <= 6 ? 'DEVUELTA' : 'RECHAZADA', 'reason' => 'Respuesta administrativa '.$number, 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    }
    $this->actingAs($cashier);
    $page = Livewire::test(CashBoxOperations::class);
    expect($page->instance()->workspace['correction_alerts'])->toBe(['unread' => 7, 'returned' => 6]);
    expect($page->instance()->historialSolicitudesAction()->getBadge())->toBe('6');
    expect($page->instance()->workspace['correction_feedback'])->toHaveCount(5);
    expect(array_unique(array_column($page->instance()->workspace['correction_feedback'], 'correction_status')))->toBe(['DEVUELTA']);
    $history = $request->fresh()->historyEntries();
    $read = app(MarkCashBoxCorrectionReadAction::class);
    expect(fn () => $read->execute(c2uiUser(), $request->fresh(), 1))->toThrow(AuthorizationException::class);
    expect(fn () => $read->execute($admin, $request->fresh(), 1))->toThrow(AuthorizationException::class);
    expect($request->fresh()->viewed_response_version)->toBe(0);
    $read->execute($cashier, $request->fresh(), 1);
    $when = $request->fresh()->response_read_at;
    $this->travel(1)->minutes();
    $read->execute($cashier, $request->fresh(), 1);
    expect($request->fresh()->response_read_at->equalTo($when))->toBeTrue();
    expect($request->fresh()->historyEntries())->toBe($history);
    $this->assertDatabaseCount('cash_box_corrections', 7);
});

it('refreshes only the correction indicator and skips unchanged renders without loading financial data', function () {
    $cashier = c2uiUser();
    $admin = c2uiUser('administrador');
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($cashier, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($cashier, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $operation = app(RecordQuickCashBoxCommissionAction::class)->execute($cashier, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 1, 'request_key' => (string) Str::uuid()]);
    $request = app(RequestCashBoxCorrectionAction::class)->execute($cashier, $operation, ['reason' => 'Revisar factura', 'request_key' => (string) Str::uuid()]);
    $this->actingAs($cashier);
    $indicator = Livewire::test(CorrectionAlerts::class, ['accessId' => $access->id, 'initialAlerts' => ['unread' => 0, 'returned' => 0]]);
    app(DecideCashBoxCorrectionAction::class)->execute($admin, $request, ['decision' => 'DEVUELTA', 'reason' => 'Completar numero', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $queries = [];
    $capture = false;
    DB::listen(function ($query) use (&$queries, &$capture): void {
        if ($capture) {
            $queries[] = strtolower($query->sql);
        }
    });
    $capture = true;
    $indicator->call('refreshAlerts')->assertSet('alerts', ['unread' => 1, 'returned' => 1])->assertDispatched('cash-box-correction-alerts-updated');
    $capture = false;
    $sql = implode(' ', $queries);
    expect($sql)->not->toContain('cash_box_operations', 'cash_box_configurations', 'cash_box_closes', 'sum("fee_cents")');
    expect(count(array_filter($queries, fn ($sql) => str_contains($sql, 'cash_box_corrections'))))->toBe(1);
    $indicator->call('refreshAlerts');
    expect($indicator->effects)->not->toHaveKey('html');
    expect($request->fresh()->viewed_response_version)->toBe(0);
    $indicator->dispatch('cash-box-correction-changed', accessId: $access->id + 1);
    expect($indicator->effects)->not->toHaveKey('html');
    app(MarkCashBoxCorrectionReadAction::class)->execute($cashier, $request->fresh(), 1);
    $indicator->dispatch('cash-box-correction-changed', accessId: $access->id)->assertSet('alerts', ['unread' => 0, 'returned' => 1]);
});

it('stops isolated correction refresh when the session closes or access is revoked', function (string $case) {
    $cashier = c2uiUser();
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($cashier, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($cashier, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $this->actingAs(c2uiUser());
    Livewire::test(CorrectionAlerts::class, ['accessId' => $access->id])->assertSet('active', false)->assertSet('alerts', ['unread' => 0, 'returned' => 0]);
    $this->actingAs($cashier);
    $indicator = Livewire::test(CorrectionAlerts::class, ['accessId' => $access->id, 'initialAlerts' => ['unread' => 1, 'returned' => 1]]);
    if ($case === 'closed') {
        $session->update(['status' => 'CERRADA', 'active_slot' => null]);
    }
    if ($case === 'inactive') {
        $cashier->forceFill(['is_active' => false])->save();
    }
    if ($case === 'restricted') {
        $cashier->restrictedPermissions()->attach(Permission::findByName('cajas.operar', 'web')->id);
    }
    $indicator->call('refreshAlerts')->assertSet('active', false)->assertSet('alerts', ['unread' => 0, 'returned' => 0])->assertDontSeeHtml('wire:poll');
    $queries = [];
    $capture = true;
    DB::listen(function ($query) use (&$queries, &$capture): void {
        if ($capture) {
            $queries[] = $query->sql;
        }
    });
    $indicator->call('refreshAlerts');
    $capture = false;
    expect(implode(' ', $queries))->not->toContain('cash_box_accesses', 'cash_box_corrections', 'cash_box_sessions');
    expect($indicator->effects)->not->toHaveKey('html');
})->with(['closed', 'inactive', 'restricted']);

it('shows recorded workday delay without generating another incident for a second box', function (string $time, int $delay) {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 '.$time, 'America/La_Paz'));
    $user = c2uiUser();
    $this->actingAs($user);
    $day = ScheduledWorkday::create([
        'user_id' => $user->id, 'created_by' => c2uiUser('administrador')->id,
        'work_date' => '2026-10-15', 'period' => 'manana',
        'starts_at' => CarbonImmutable::parse('2026-10-15 08:00', 'America/La_Paz')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-10-15 14:00', 'America/La_Paz')->utc(),
    ]);
    $firstId = null;
    foreach ([c2uiBox(), c2uiBox()] as $box) {
        $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
        $session = app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
        $firstId ??= $session->id;
        $this->travel(20)->minutes();
        $workspace = app(CashBoxWorkspaceQuery::class)->execute($user, $access->id);
        expect($workspace['session_timing']['delay_minutes'])->toBe($delay)
            ->and($workspace['session_timing']['reference_is_this_box'])->toBe($session->id === $firstId)
            ->and($workspace['elapsed_label'])->toBe('0 h 20 min')
            ->and($workspace['elapsed_seconds'])->toBe(1200);
        Livewire::test(CashBoxOperations::class)->assertSee($delay > 0 ? 'Retraso registrado: '.$delay.' min' : 'Sin retraso registrado');
    }
    $this->assertDatabaseCount('time_incidents', 1);
    $this->assertDatabaseCount('attendances', 1);
})->with([['10:00', 105], ['08:00', 0]]);

it('distinguishes missing schedule and missing opening evaluation without claiming punctuality', function () {
    $user = c2uiUser();
    $this->actingAs($user);
    $box = c2uiBox();
    $access = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
    $session = app(OpenCashBoxAction::class)->execute($user, $access->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $query = app(CashBoxWorkspaceQuery::class);
    expect($query->execute($user, $access->id)['session_timing']['status'])->toBe('NO_SCHEDULE');
    $day = ScheduledWorkday::create([
        'user_id' => $user->id, 'created_by' => c2uiUser('administrador')->id, 'work_date' => '2026-10-15', 'period' => 'manana',
        'starts_at' => CarbonImmutable::parse('2026-10-15 08:00', 'America/La_Paz')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-10-15 14:00', 'America/La_Paz')->utc(),
    ]);
    $session->update(['scheduled_workday_id' => $day->id]);
    expect($query->execute($user, $access->id)['session_timing']['status'])->toBe('NOT_EVALUATED');
    $this->assertDatabaseCount('time_incidents', 0);
});

it('previews balances live and discards opening inputs without financial writes', function () {
    $box = c2uiBox();
    $this->actingAs(c2uiUser());
    $page = Livewire::test(CashBoxOperations::class)
        ->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])
        ->set('openingBalances.efectivo', '100.10')->set('openingBalances.yape', '50.03');
    expect($page->instance()->openingPreview['total_cents'])->toBe(1515013);
    $this->assertDatabaseCount('cash_box_sessions', 0);
    $page->set('openingBalances.bcp', 'invalid')->call('confirmOpening')->assertHasErrors(['openingBalances.bcp']);
    $page->callAction('salirDeVista')->assertSet('accessId', null)->assertSet('selectedCashBoxId', null)->assertSet('openingBalances', []);
    $this->assertDatabaseCount('cash_box_sessions', 0);
});

it('formats opening integers compactly while preserving cents', function () {
    expect(Bolivianos::formatCompact(42300))->toBe('423')
        ->and(Bolivianos::formatCompact(10010))->toBe('100.10')
        ->and(Bolivianos::formatCompact(5003))->toBe('50.03')
        ->and(Bolivianos::formatCompact(0))->toBe('0');
});

it('keeps the chosen open box in the URL and restores it on catalog refresh without PIN', function () {
    $user = c2uiUser();
    $boxes = [c2uiBox(), c2uiBox()];
    $this->actingAs($user);
    foreach ($boxes as $box) {
        $a = app(StartCashBoxAccessAction::class)->execute($user, $box, '1234', (string) Str::uuid());
        app(OpenCashBoxAction::class)->execute($user, $a->id, ['balances' => ['efectivo' => '2000', 'bcp' => '15000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    }
    $audits = DB::table('user_audits')->count();
    Livewire::test(CashBoxCatalog::class)->call('resumeBox', $boxes[0]->id)->assertSet('selectedCashBoxId', $boxes[0]->id)
        ->call('resumeBox', $boxes[1]->id)->assertSet('selectedCashBoxId', $boxes[1]->id);
    $this->get(CashBoxCatalog::getUrl(['cashBox' => $boxes[0]->id]))->assertOk()->assertSee('Registrar comisión')->assertSee($boxes[0]->name);
    $this->get(CashBoxOperations::getUrl(['cashBox' => $boxes[1]->id]))->assertOk()->assertSee('Registrar comisión');
    expect(DB::table('user_audits')->count())->toBe($audits);
    $this->assertDatabaseCount('cash_box_sessions', 2);
    $this->get(CashBoxCatalog::getUrl())->assertOk()->assertDontSee('Registrar comisión');
    $this->actingAs(c2uiUser('administrador'));
    $this->get(CashBoxCatalog::getUrl(['cashBox' => $boxes[0]->id]))->assertForbidden();
});

it('uses the optional shared count in opening without applying cancelled edits', function () {
    $box = c2uiBox();
    $this->actingAs(c2uiUser());
    $page = Livewire::test(CashBoxCatalog::class)
        ->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])
        ->assertSee('Realizar arqueo')->call('openCashCount')->assertSet('cashCountOpen', true)
        ->set('cashCountQuantities.b100', '3')->set('cashCountPacked.m5', '1000')->call('dismissCashCount')
        ->assertSet('openingBalances.efectivo', '2000')->assertSet('acceptedCashCount', null);
    $page->call('openCashCount')->set('cashCountQuantities.b100', '3')->set('cashCountPacked.m5', '1000')
        ->call('applyCashCount')->assertSet('openingBalances.efectivo', '1300')->assertSet('cashCountOpen', false);
    $accepted = $page->get('acceptedCashCount');
    $this->assertDatabaseCount('cash_box_sessions', 0);
    $page->call('openCashCount')->set('cashCountQuantities.b100', '5000')->call('dismissCashCount')
        ->assertSet('openingBalances.efectivo', '1300')->assertSet('acceptedCashCount', $accepted);
    $page->set('openingBalances.bcp', '15700')->assertSet('acceptedCashCount', $accepted)->call('confirmOpening')->assertHasNoErrors();
    $session = CashBoxSession::sole();
    expect($session->comparison['count_details']['cash_count'])->toBe($accepted)
        ->and($session->comparison['count_details']['method'])->toBe('ARQUEO');
    $page->call('confirmOpening')->assertHasNoErrors();
    $this->assertDatabaseCount('cash_box_sessions', 1);
});

it('invalidates an opening count only when manual cash changes and blocks its use without PIN access', function () {
    $box = c2uiBox();
    $this->actingAs(c2uiUser());
    Livewire::test(CashBoxCatalog::class)->call('openCashCount')->assertForbidden();
    $page = Livewire::test(CashBoxCatalog::class)
        ->callTableAction('ingresar', $box, data: ['pin' => '1234', 'request_key' => (string) Str::uuid()])
        ->call('openCashCount')->set('cashCountQuantities.b100', '-1')->call('applyCashCount')->assertHasErrors(['cashCount'])
        ->assertSet('openingBalances.efectivo', '2000');
    $page->set('cashCountQuantities.b100', '20')->call('applyCashCount')->set('openingBalances.efectivo', '1999')->assertSet('acceptedCashCount', null)
        ->call('openCashCount')->call('dismissCashCount')->callAction('salirDeVista')->assertSet('acceptedCashCount', null)->assertSet('cashCountOpen', false);
    $this->assertDatabaseCount('cash_box_sessions', 0);
});
