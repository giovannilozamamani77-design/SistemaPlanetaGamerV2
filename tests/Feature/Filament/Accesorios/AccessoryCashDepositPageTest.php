<?php

use App\Domain\Accesorios\Actions\RecordAccessoryCashDepositAction;
use App\Domain\Accesorios\Models\AccessoryCashDeposit;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Queries\AccessoryCashDepositQuery;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Filament\Pages\Accesorios\AccessoryInventory;
use App\Filament\Pages\Accesorios\AccessoryQuickSale;
use App\Livewire\Accesorios\AccessoryCashDepositHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function depositPageFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class]);
    Http::preventStrayRequests();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['name' => 'Cajera depósitos']);
    $cashier->assignRole('cajera');
    test()->actingAs($cashier);
    $a = CardDepositAccount::create(['name' => 'Cuenta destino', 'bank' => 'Banco prueba', 'holder' => 'Titular', 'number' => '12345', 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    $fund = CommercialFund::create(['code' => 'GENERAL', 'balance_cents' => 10000, 'revision' => 0]);

    return [$admin, $cashier, $a, $fund];
}
function depositPageRecord($cashier, $a, $fund, $amount = '10')
{
    return app(RecordAccessoryCashDepositAction::class)->execute($cashier, ['account_id' => $a->id, 'account_revision' => 1, 'expected_revision' => $fund->fresh()->revision, 'amount' => $amount, 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
}
it('lets the cashier record a deposit from quick sale without clearing the basket', function () {
    [$admin,$cashier,$a,$fund] = depositPageFixture();
    $page = Livewire::test(AccessoryQuickSale::class)->mountAction('depositCash')->assertActionDataSet(['account_id' => $a->id]);
    test()->view('accesorios.deposit-preview', app(AccessoryCashDepositQuery::class)->quote($cashier, $a->id))->assertSee('Efectivo disponible de Accesorios')->assertSee('Cuenta destino');
    $key = $page->get('requestKey');
    $page->setActionData(['amount' => '40', 'confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($fund->fresh()->balance_cents)->toBe(6000)->and(AccessoryCashDeposit::sole()->user_id)->toBe($cashier->id)->and($page->get('requestKey'))->toBe($key);
    Http::assertNothingSent();
});
it('shows deposit errors inside the modal without reducing cash', function () {
    [$admin,$cashier,$a,$fund] = depositPageFixture();
    Livewire::test(AccessoryQuickSale::class)->mountAction('depositCash')->setActionData(['amount' => '101', 'confirmed' => true])->callMountedAction()->assertHasActionErrors(['amount']);
    expect($fund->fresh()->balance_cents)->toBe(10000)->and(AccessoryCashDeposit::count())->toBe(0);
});
it('paginates the deposit history and only exposes reversal to Administration', function () {
    [$admin,$cashier,$a,$fund] = depositPageFixture();
    $rows = collect();
    for ($i = 0; $i < 6; $i++) {
        $rows->push(depositPageRecord($cashier, $a, $fund));
    }
    $history = Livewire::test(AccessoryCashDepositHistory::class)->assertCanSeeTableRecords($rows->slice(1))->assertCanNotSeeTableRecords($rows->take(1));
    $history->assertActionHidden(TestAction::make('voidDeposit')->table($rows->last()));
    $history->call('gotoPage', 2)->assertCanSeeTableRecords($rows->take(1));
    Livewire::test(AccessoryInventory::class)->assertActionVisible('cashDepositHistory');
    test()->actingAs($admin);
    Livewire::test(AccessoryCashDepositHistory::class)->mountAction(TestAction::make('voidDeposit')->table($rows->last()))->setActionData(['reason' => 'Registro equivocado', 'confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($fund->fresh()->balance_cents)->toBe(5000)->and($rows->last()->fresh()->reversal)->not->toBeNull();
});
it('serves the account QR only to an authorized accessory operator and checks its revision', function () {
    [$admin,$cashier,$a,$fund] = depositPageFixture();
    Storage::fake('local');
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII=');
    Storage::disk('local')->put('qr/example.png', $bytes);
    $a->update(['qr_path' => 'qr/example.png', 'qr_sha256' => hash('sha256', $bytes)]);
    test()->get(route('accesorios.deposito-qr', ['account' => $a->id, 'revision' => 1]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    test()->get(route('accesorios.deposito-qr', ['account' => $a->id, 'revision' => 2]))->assertStatus(409);
    $cashier->revokePermissionTo('accesorios.ventas.registrar');
    $other = User::factory()->create();
    test()->actingAs($other);
    test()->get(route('accesorios.deposito-qr', ['account' => $a->id]))->assertForbidden();
});

it('C347 filters historical deposits by Bolivia dates cashier and account while retaining pagination and administrator reversal', function () {
    [$admin,$cashier,$a,$fund] = depositPageFixture();
    $b = CardDepositAccount::create(['name' => 'Otra cuenta', 'bank' => 'Banco prueba', 'holder' => 'Titular', 'number' => '67890', 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    $records = [];
    foreach (['2026-10-23 03:59:59', '2026-10-23 04:00:00', '2026-10-24 03:59:59', '2026-10-24 04:00:00'] as $time) {
        $this->travelTo(CarbonImmutable::parse($time, 'UTC'));
        $records[] = depositPageRecord($cashier, $a, $fund);
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-23 16:00:00', 'UTC'));
    $other = depositPageRecord($admin, $a, $fund);
    $extra = depositPageRecord($cashier, $b, $fund);
    $a->update(['is_active' => false, 'name' => 'Cuenta renombrada']);
    $cashier->update(['name' => 'Nombre actualizado']);
    $query = app(AccessoryCashDepositQuery::class);
    $options = $query->historyFilterOptions($cashier);
    expect($options['accounts'][$a->id])->toContain('Cuenta destino')->not->toContain('renombrada');
    expect($options['users'][$cashier->id])->toBe('Cajera depósitos');
    $day = ['from' => '2026-10-23', 'until' => '2026-10-23'];
    expect($query->filterHistory($query->history($cashier), $day)->count())->toBe(4);
    $filter = $day + ['user_id' => $cashier->id, 'account_id' => $a->id];
    $page = Livewire::test(AccessoryCashDepositHistory::class)->assertSee('Filtrar depósitos')->call('gotoPage', 2)->assertCanSeeTableRecords([$records[0]]);
    $page->filterTable('history', $filter)->assertCanSeeTableRecords([$records[1], $records[2]])->assertCanNotSeeTableRecords([$records[0], $records[3], $other, $extra]);
    $page->assertActionHidden(TestAction::make('voidDeposit')->table($records[1]));
    $page->resetTableFilters()->call('gotoPage', 2)->assertCanSeeTableRecords([$records[0]]);
    test()->actingAs($admin);
    Livewire::test(AccessoryCashDepositHistory::class)->filterTable('history', $filter)->assertActionVisible(TestAction::make('voidDeposit')->table($records[1]))
        ->mountAction(TestAction::make('voidDeposit')->table($records[1]))->setActionData(['reason' => 'Corrección filtrada', 'confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect($fund->fresh()->balance_cents)->toBe(5000)->and($records[1]->fresh()->reversal)->not->toBeNull();
    Http::assertNothingSent();
});

it('C347 rejects malformed history filters without changing records or money', function ($filter) {
    [$admin,$cashier,$a,$fund] = depositPageFixture();
    depositPageRecord($cashier, $a, $fund);
    $q = app(AccessoryCashDepositQuery::class);
    expect($q->filterHistory($q->history($cashier), $filter)->count())->toBe(0);
    expect($q->history($cashier)->count())->toBe(1)->and($fund->fresh()->balance_cents)->toBe(9000);
})->with([
    [['from' => 'invalid']],
    [['from' => '2026-02-30']],
    [['from' => '2026-10-24', 'until' => '2026-10-23']],
    [['user_id' => -1]],
    [['account_id' => ['bad']]],
]);

it('C347 supports one sided date boundaries and returns no matches for unknown IDs', function () {
    [$admin,$cashier,$a,$fund] = depositPageFixture();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 04:00:00', 'UTC'));
    depositPageRecord($cashier, $a, $fund);
    $q = app(AccessoryCashDepositQuery::class);
    expect($q->filterHistory($q->history($cashier), ['from' => '2026-10-24'])->count())->toBe(1);
    expect($q->filterHistory($q->history($cashier), ['until' => '2026-10-23'])->count())->toBe(0);
    expect($q->filterHistory($q->history($cashier), ['account_id' => 999999])->count())->toBe(0);
    expect($q->filterHistory($q->history($cashier), ['user_id' => 999999])->count())->toBe(0);
});

it('C347 does not expose history filter options to inactive or unauthorized users', function () {
    [$admin,$cashier,$a,$fund] = depositPageFixture();
    depositPageRecord($cashier, $a, $fund);
    $other = User::factory()->create();
    $q = app(AccessoryCashDepositQuery::class);
    expect(fn () => $q->historyFilterOptions($other))->toThrow(AuthorizationException::class);
    $cashier->forceFill(['is_active' => false])->save();
    expect(fn () => $q->historyFilterOptions($cashier))->toThrow(AuthorizationException::class);
});
