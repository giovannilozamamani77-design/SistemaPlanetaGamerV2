<?php

use App\Domain\Tarjetas\Actions\RecordCardCashDepositAction;
use App\Domain\Tarjetas\Actions\RecordCardCashFundingAction;
use App\Domain\Tarjetas\Actions\RecordCardStockBatchAction;
use App\Domain\Tarjetas\Actions\SaveCardDepositAccountAction;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Domain\Tarjetas\Queries\CardDepositQuery;
use App\Filament\Pages\Tarjetas\CardCashDeposits;
use App\Filament\Pages\Tarjetas\CardDepositAccounts;
use App\Filament\Pages\Tarjetas\InventoryMovements;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Tarjetas\CardCashFundingPermissionsSeeder;
use Database\Seeders\Tarjetas\CardDepositsPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function c173Fixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardDepositsPermissionsSeeder::class]);
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    Storage::fake('local');
    Storage::disk('local')->put('card-deposit-qr/test.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6eAAAAABJRU5ErkJggg=='));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 100000, 'initialized_at' => now(), 'initialized_by' => $admin->id, 'session_control_started_at' => now()]);
    CardStoreSession::create(['card_store_id' => $store->id, 'user_id' => $cashier->id, 'active_slot' => 1, 'opened_at' => now()]);
    $data = ['name' => 'Cuenta principal', 'bank' => 'Banco prueba', 'number' => 'TEST-123', 'holder' => 'Administración prueba', 'qr_path' => 'card-deposit-qr/test.png', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()];
    $account = app(SaveCardDepositAccountAction::class)->execute($admin, $data);
    test()->actingAs($cashier);

    return [$admin, $cashier, $account, $data];
}
function c173Data(CardDepositAccount $a): array
{
    return ['account_id' => $a->id, 'account_revision' => $a->revision, 'amount' => '250.5', 'request_key' => (string) Str::uuid()];
}
it('C173 subtracts only cash once and preserves destination and optional transaction reference', function () {
    [$admin,$u,$a] = c173Fixture();
    $data = c173Data($a);
    $data['operation_reference'] = 'TX-test';
    $act = app(RecordCardCashDepositAction::class);
    $m = $act->execute($u, $data);
    expect($act->execute($u, $data)->id)->toBe($m->id);
    expect(CardStore::sole()->cash_balance_cents)->toBe(74950)->and(CardInventoryMovement::count())->toBe(1)->and(CardStock::count())->toBe(0)->and($m->cash_delta_cents)->toBe(-25050)->and($m->destination_snapshot['number'])->toBe('TEST-123')->and($m->user_id)->toBe($u->id)->and($m->reference)->toBe('TX-test');
    $a->update(['name' => 'Otro nombre', 'is_active' => false, 'revision' => 2]);
    expect($act->execute($u, $data)->id)->toBe($m->id)->and($m->fresh()->destination_snapshot['name'])->toBe('Cuenta principal');
    expect(fn () => $act->execute($u, array_replace($data, ['amount' => '251'])))->toThrow(ValidationException::class);
});
it('C173 rejects unavailable money invalid amounts inactive or changed destination without writes', function () {
    [$admin,$u,$a] = c173Fixture();
    $act = app(RecordCardCashDepositAction::class);
    foreach (['0', '-1', '1000.01', '1.001'] as $amount) {
        expect(fn () => $act->execute($u, array_replace(c173Data($a), ['amount' => $amount])))->toThrow(ValidationException::class);
    }
    $old = c173Data($a);
    $a->update(['revision' => 2]);
    expect(fn () => $act->execute($u, $old))->toThrow(ValidationException::class);
    $a->update(['is_active' => false]);
    expect(fn () => $act->execute($u, c173Data($a->fresh())))->toThrow(ValidationException::class);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000)->and(CardInventoryMovement::count())->toBe(0);
});
it('C173 rolls back cash and ledger if saving audit fails', function () {
    [$admin,$u,$a] = c173Fixture();
    DB::statement("CREATE TRIGGER fail_deposit_command BEFORE INSERT ON card_inventory_commands WHEN NEW.action = 'CASH_ADMIN_DEPOSIT' BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    expect(fn () => app(RecordCardCashDepositAction::class)->execute($u, c173Data($a)))->toThrow(QueryException::class);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000)->and(CardInventoryMovement::count())->toBe(0);
});
it('C173 restricts account management to administration and protects private QR', function () {
    [$admin,$u,$a,$data] = c173Fixture();
    expect(fn () => app(SaveCardDepositAccountAction::class)->execute($u, $data))->toThrow(AuthorizationException::class);
    test()->get(CardDepositAccounts::getUrl())->assertForbidden();
    test()->get(route('tarjetas.deposito-qr', $a->id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $u->forceFill(['is_active' => false])->save();
    test()->get(route('tarjetas.deposito-qr', $a->id))->assertForbidden();
    test()->actingAs($admin);
    Livewire::test(CardDepositAccounts::class)->assertSee('Cuenta principal')->assertActionVisible('createAccount');
});
it('C173 supports multiple accounts rejects unsafe QR and stale configuration without losing history', function () {
    [$admin,$u,$a,$data] = c173Fixture();
    $act = app(SaveCardDepositAccountAction::class);
    expect(fn () => $act->execute($admin, array_replace($data, ['qr_path' => '../bad.png', 'request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    $b = $act->execute($admin, array_replace($data, ['name' => 'Segunda cuenta', 'request_key' => (string) Str::uuid()]));
    expect(CardDepositAccount::count())->toBe(2);
    $edit = array_replace($data, ['account_id' => $a->id, 'expected_revision' => 1, 'is_active' => false, 'request_key' => (string) Str::uuid()]);
    $act->execute($admin, $edit);
    expect(fn () => $act->execute($admin, array_replace($edit, ['request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    expect(app(CardDepositQuery::class)->options($u))->toHaveCount(1);
});
it('C173 preselects sole active account cancels without writing and registers without a transaction number', function () {
    [$admin,$u,$a] = c173Fixture();
    $p = Livewire::test(QuickCardSale::class)->assertActionVisible('depositCash')->mountAction('depositCash')->assertSet('mountedActions.0.data.account_id', $a->id);
    expect($p->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('Banco prueba', 'TEST-123', 'QR de Banco prueba', 'Bs 1000');
    $p->unmountAction();
    expect(CardInventoryMovement::count())->toBe(0);
    $p->mountAction('depositCash')->set('mountedActions.0.data.amount', '100')->callMountedAction()->assertHasNoActionErrors()->assertSee('Total a cobrar');
    expect(CardStore::sole()->cash_balance_cents)->toBe(90000);
    expect(CardInventoryMovement::sole()->reference)->toBeNull();
});

it('C173 uses latest available cash for competing deposits and rejects revoked access or uninitialized store', function () {
    [$admin,$u,$a] = c173Fixture();
    $act = app(RecordCardCashDepositAction::class);
    $act->execute($u, array_replace(c173Data($a), ['amount' => '800']));
    expect(fn () => $act->execute($u, array_replace(c173Data($a), ['amount' => '800'])))->toThrow(ValidationException::class);
    expect(CardStore::sole()->cash_balance_cents)->toBe(20000)->and(CardInventoryMovement::count())->toBe(1);
    CardStore::sole()->update(['initialized_at' => null]);
    expect(fn () => $act->execute($u, array_replace(c173Data($a), ['amount' => '10'])))->toThrow(ValidationException::class);
    $u->forceFill(['is_active' => false])->save();
    expect(fn () => $act->execute($u, c173Data($a)))->toThrow(AuthorizationException::class);
});
it('C173 offers no inactive destinations and multiple active accounts require selection', function () {
    [$admin,$u,$a,$data] = c173Fixture();
    app(SaveCardDepositAccountAction::class)->execute($admin, array_replace($data, ['name' => 'Otra cuenta', 'request_key' => (string) Str::uuid()]));
    Livewire::test(QuickCardSale::class)->mountAction('depositCash')->assertSet('mountedActions.0.data.account_id', null);
    CardDepositAccount::query()->update(['is_active' => false]);
    $page = Livewire::test(QuickCardSale::class)->mountAction('depositCash')->assertSet('mountedActions.0.data.account_id', null);
    expect($page->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('contacta con Administración');
    $page->set('mountedActions.0.data.amount', '1')->callMountedAction()->assertHasActionErrors(['account_id']);
    expect(CardInventoryMovement::count())->toBe(0);
});
it('C173 preserves QR history requires replacement for changed banking details and rejects corrupted QR', function () {
    [$admin,$u,$a,$data] = c173Fixture();
    $edit = array_replace($data, ['account_id' => $a->id, 'expected_revision' => 1, 'number' => 'TEST-456', 'request_key' => (string) Str::uuid()]);
    expect(fn () => app(SaveCardDepositAccountAction::class)->execute($admin, $edit))->toThrow(ValidationException::class);
    $original = Storage::disk('local')->get($a->qr_path);
    Storage::disk('local')->put('card-deposit-qr/new.png', $original.'changed');
    $updated = app(SaveCardDepositAccountAction::class)->execute($admin, array_replace($edit, ['qr_path' => 'card-deposit-qr/new.png']));
    expect($updated->revision)->toBe(2)->and(Storage::disk('local')->exists('card-deposit-qr/test.png'))->toBeTrue();
    test()->get(route('tarjetas.deposito-qr', $a->id).'?revision=1')->assertStatus(409);
    Storage::disk('local')->put('card-deposit-qr/new.png', 'bad');
    test()->get(route('tarjetas.deposito-qr', $a->id))->assertNotFound();
});

it('C173 administration creates and disables accounts through the actual configuration form', function () {
    [$admin,$u,$a,$data] = c173Fixture();
    test()->actingAs($admin);
    $data['qr_path'] = [$data['qr_path']];
    $page = Livewire::test(CardDepositAccounts::class)->callAction('createAccount', data: array_replace($data, ['name' => 'Cuenta creada desde formulario', 'request_key' => (string) Str::uuid()]))->assertHasNoActionErrors();
    $created = CardDepositAccount::where('name', 'Cuenta creada desde formulario')->sole();
    $page->callTableAction('editAccount', $created, data: array_replace($data, ['account_id' => $created->id, 'name' => $created->name, 'expected_revision' => 1, 'is_active' => false, 'request_key' => (string) Str::uuid()]))->assertHasNoTableActionErrors();
    expect($created->fresh()->is_active)->toBeFalse()->and(CardInventoryMovement::count())->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(100000);
});

it('C174 cashier reads paginated deposits across shifts with original destination and can deposit from history', function () {
    [$admin,$u,$a] = c173Fixture();
    $first = null;
    for ($i = 0; $i < 6; $i++) {
        $actor = $i % 2 ? $admin : $u;
        $m = app(RecordCardCashDepositAction::class)->execute($actor, array_replace(c173Data($a), ['amount' => '10', 'operation_reference' => $i === 0 ? 'OP-FIRST' : null]));
        $first ??= $m;
    }
    $a->update(['name' => 'Nombre nuevo', 'number' => 'OTRO-NUMERO', 'is_active' => false, 'revision' => 2]);
    $page = Livewire::test(CardCashDeposits::class)->assertActionVisible('depositCash')->assertActionHidden('accounts')->assertSee('Cuenta principal')->assertSee('TEST-123')->assertSee('Sin número')->assertSee($admin->name)->assertCanNotSeeTableRecords([$first])->call('gotoPage', 2)->assertCanSeeTableRecords([$first])->assertSee('OP-FIRST')->assertDontSee('Nombre nuevo')->assertDontSee('OTRO-NUMERO');
    $page->mountTableAction('detail', $first);
    expect($page->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('Administración prueba', 'TEST-123', 'Bs 1000', 'Bs 990');
    expect(app(CardDepositQuery::class)->history($u)->count())->toBe(6);
    $this->actingAs($admin);
    Livewire::test(CardCashDeposits::class)->assertActionVisible('accounts');
});
it('C174 excludes other movements and denies history to inactive or unauthorized users', function () {
    [$admin,$u,$a] = c173Fixture();
    app(RecordCardCashDepositAction::class)->execute($u, array_replace(c173Data($a), ['amount' => '10']));
    $m = CardInventoryMovement::sole();
    CardInventoryMovement::create(['card_store_id' => $m->card_store_id, 'user_id' => $u->id, 'kind' => 'INITIAL_CASH', 'quantity' => 0, 'cash_delta_cents' => 0, 'cash_before_cents' => 99000, 'cash_after_cents' => 99000]);
    $q = app(CardDepositQuery::class);
    expect($q->history($u)->count())->toBe(1);
    $u->forceFill(['is_active' => false])->save();
    expect(fn () => $q->history($u))->toThrow(AuthorizationException::class);
    $this->get(CardCashDeposits::getUrl())->assertForbidden();
});

it('C174 cashier registers a deposit directly from history with no account configuration access', function () {
    [$admin,$u,$a] = c173Fixture();
    Livewire::test(CardCashDeposits::class)->assertActionHidden('accounts')->mountAction('depositCash')->set('mountedActions.0.data.amount', '25')->set('mountedActions.0.data.operation_reference', 'OP-HISTORY')->callMountedAction()->assertHasNoActionErrors()->assertSee('OP-HISTORY')->assertSee($u->name);
    expect(CardInventoryMovement::sole()->user_id)->toBe($u->id)->and(CardStore::sole()->cash_balance_cents)->toBe(97500);
});

it('C175 filters deposits by Bolivia date cashier and historical disabled account with reset and pagination', function () {
    [$admin,$u,$a,$data] = c173Fixture();
    $b = app(SaveCardDepositAccountAction::class)->execute($admin, array_replace($data, ['name' => 'Segunda cuenta', 'request_key' => (string) Str::uuid()]));
    $records = [];
    foreach (['2026-10-23 03:59:59', '2026-10-23 04:00:00', '2026-10-24 03:59:59', '2026-10-24 04:00:00'] as $time) {
        $this->travelTo(CarbonImmutable::parse($time, 'UTC'));
        $records[] = app(RecordCardCashDepositAction::class)->execute($u, array_replace(c173Data($a), ['amount' => '10']));
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-23 15:00:00', 'UTC'));
    $other = app(RecordCardCashDepositAction::class)->execute($admin, array_replace(c173Data($b), ['amount' => '10']));
    $extra = app(RecordCardCashDepositAction::class)->execute($u, array_replace(c173Data($b), ['amount' => '10']));
    $a->update(['is_active' => false, 'name' => 'Renombrada después']);
    $q = app(CardDepositQuery::class);
    $options = $q->historyFilterOptions($u);
    expect($options['accounts'][$a->id])->toContain('Cuenta principal')->not->toContain('Renombrada');
    expect($options['users'])->toHaveKeys([$u->id, $admin->id]);
    $day = ['from' => '2026-10-23', 'until' => '2026-10-23'];
    expect($q->filterHistory($q->history($u), $day)->count())->toBe(4);
    $filter = $day + ['user_id' => $u->id, 'account_id' => $a->id];
    $page = Livewire::test(CardCashDeposits::class)->assertSee('Filtrar depósitos')->filterTable('history', $filter)->assertCanSeeTableRecords([$records[1], $records[2]])->assertCanNotSeeTableRecords([$records[0], $records[3], $other, $extra]);
    $page->resetTableFilters()->call('gotoPage', 2)->assertCanSeeTableRecords([$records[0]]);
    expect($q->filterHistory($q->history($u), ['from' => 'invalid'])->count())->toBe(0);
    expect($q->filterHistory($q->history($u), ['from' => '2026-10-24', 'until' => '2026-10-23'])->count())->toBe(0);
    expect($q->history($u)->count())->toBe(6);
});

function c176Fixture(): array
{
    $fixture = c173Fixture();
    test()->seed(CardCashFundingPermissionsSeeder::class);

    return $fixture;
}

it('C176 administration cash funding increases available cash once without stock or sales', function () {
    [$admin,$u,$a] = c176Fixture();
    $data = ['amount' => '250.50', 'reference' => 'Entrega para compra Tigo', 'request_key' => (string) Str::uuid()];
    $act = app(RecordCardCashFundingAction::class);
    $m = $act->execute($admin, $data);
    expect($act->execute($admin, $data)->id)->toBe($m->id);
    expect(CardStore::sole()->cash_balance_cents)->toBe(125050)->and(CardInventoryMovement::count())->toBe(1)->and($m->kind)->toBe('ADMIN_CASH_IN')->and($m->cash_delta_cents)->toBe(25050)->and($m->user_id)->toBe($admin->id)->and($m->reference)->toBe('Entrega para compra Tigo');
    expect(fn () => $act->execute($admin, array_replace($data, ['amount' => '251'])))->toThrow(ValidationException::class);
});

it('C202 rejects invalid funds unavailable initialization and individually restricted cashier without changes', function () {
    [$admin,$u] = c176Fixture();
    $act = app(RecordCardCashFundingAction::class);
    foreach (['0', '-1', '1.001', '9999999999999'] as $amount) {
        expect(fn () => $act->execute($admin, ['amount' => $amount, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    }
    $u->restrictedPermissions()->attach(Permission::findByName('tarjetas.efectivo.aportar', 'web'));
    expect(fn () => $act->execute($u, ['amount' => '10', 'request_key' => (string) Str::uuid()]))->toThrow(AuthorizationException::class);
    CardStore::sole()->update(['initialized_at' => null]);
    expect(fn () => $act->execute($admin, ['amount' => '10', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(CardInventoryMovement::count())->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(100000);
});
it('C176 cash funding rollback is atomic and revoked administration cannot retry', function () {
    [$admin,$u] = c176Fixture();
    $act = app(RecordCardCashFundingAction::class);
    $data = ['amount' => '10', 'request_key' => (string) Str::uuid()];
    DB::statement("CREATE TRIGGER fail_funding_command BEFORE INSERT ON card_inventory_commands WHEN NEW.action = 'CASH_ADMIN_FUNDING' BEGIN SELECT RAISE(ABORT, 'test interruption'); END");
    expect(fn () => $act->execute($admin, $data))->toThrow(QueryException::class);
    expect(CardInventoryMovement::count())->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(100000);
    DB::statement('DROP TRIGGER fail_funding_command');
    $act->execute($admin, $data);
    $admin->forceFill(['is_active' => false])->save();
    expect(fn () => $act->execute($admin, $data))->toThrow(AuthorizationException::class);
    expect(CardInventoryMovement::count())->toBe(1)->and(CardStore::sole()->cash_balance_cents)->toBe(101000);
});
it('C176 administration modal cancels without funds and cashier immediately sees confirmed cash', function () {
    [$admin,$u] = c176Fixture();
    CardStoreSession::sole()->update(['user_id' => $admin->id]);
    $this->actingAs($admin);
    $page = Livewire::test(QuickCardSale::class)->assertActionVisible('receiveCash')->mountAction('receiveCash')->set('mountedActions.0.data.amount', '100');
    expect($page->instance()->getSchema('mountedActionSchema0')->toHtml())->toContain('Después de la entrega', 'Bs 1100');
    $page->unmountAction();
    expect(CardInventoryMovement::count())->toBe(0);
    $page->mountAction('receiveCash')->set('mountedActions.0.data.amount', '100')->callMountedAction()->assertHasNoActionErrors()->assertSee('Total a cobrar');
    expect(CardStore::sole()->cash_balance_cents)->toBe(110000);
    $this->actingAs($u);
    Livewire::test(QuickCardSale::class)->assertActionHidden('receiveCash')->assertDontSee('Total a cobrar');
    expect(CardStore::sole()->cash_balance_cents)->toBe(110000);
    Livewire::test(InventoryMovements::class)->assertSee('Efectivo entregado por Administración');
    expect(app(CardDepositQuery::class)->history($u)->count())->toBe(0);
});
it('C176 received funds pay card purchases once without treating the entry as a sale', function () {
    [$admin,$u] = c176Fixture();
    CardStore::sole()->update(['cash_balance_cents' => 0]);
    $p = CardProduct::create(['company' => 'TIGO', 'face_value_cents' => 1000, 'purchase_price_cents' => 940, 'normal_price_cents' => 1000, 'wholesale_price_cents' => 950, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
    CardStock::create(['card_store_id' => CardStore::sole()->id, 'card_product_id' => $p->id, 'quantity' => 0]);
    $data = ['amount' => '100', 'request_key' => (string) Str::uuid()];
    $act = app(RecordCardCashFundingAction::class);
    $act->execute($admin, $data);
    $act->execute($admin, $data);
    app(RecordCardStockBatchAction::class)->purchase($u, ['company' => 'TIGO', 'lines' => [['product_id' => $p->id, 'quantity' => 10, 'unit_price' => '9.4']], 'request_key' => (string) Str::uuid()]);
    expect(CardStore::sole()->cash_balance_cents)->toBe(600)->and(CardStock::sole()->quantity)->toBe(10)->and(CardInventoryMovement::count())->toBe(2)->and(CardSale::count())->toBe(0);
});
