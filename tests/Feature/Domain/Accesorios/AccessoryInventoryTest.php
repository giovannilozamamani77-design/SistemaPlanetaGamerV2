<?php

use App\Domain\Accesorios\Actions\CreateAccessoryProductAction;
use App\Domain\Accesorios\Actions\ReceiveAccessoryStockAction;
use App\Domain\Accesorios\Calculators\AverageCostCalculator;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessoryStockEntry;
use App\Domain\Accesorios\Models\ExchangeQuote;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\AccessoryInventoryQuery;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Models\User;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function inventoryAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class]);
    Http::preventStrayRequests();
    $u = User::factory()->create();
    $u->assignRole('administrador');

    return $u;
}
function inventoryProduct(User $u, array $changes = []): AccessoryProduct
{
    return app(CreateAccessoryProductAction::class)->execute($u, array_replace(['code' => 'USB-C', 'name' => 'Cable USB C', 'description' => 'Cable de carga', 'sale_price' => '20', 'request_key' => (string) Str::uuid()], $changes));
}
function inventoryEntry(AccessoryProduct $p, array $changes = []): array
{
    return array_replace(['product_id' => $p->id, 'quantity' => 10, 'unit_cost' => '10', 'kind' => 'PURCHASE', 'supplier' => 'Proveedor', 'note' => 'Compra real', 'expected_revision' => $p->fresh()->revision, 'request_key' => (string) Str::uuid()], $changes);
}
it('C251 retains each purchase cost and exact weighted stock value without changing confirmed prices', function () {
    $u = inventoryAdmin();
    $p = inventoryProduct($u);
    $a = app(ReceiveAccessoryStockAction::class);
    $first = $a->execute($u, inventoryEntry($p));
    $second = $a->execute($u, inventoryEntry($p, ['unit_cost' => '14']));
    $p->refresh();
    expect($p->quantity)->toBe(20)->and($p->stock_value_cents)->toBe(24000)->and($p->sale_price_cents)->toBe(2000);
    expect($first->unit_cost_cents)->toBe(1000)->and($second->unit_cost_cents)->toBe(1400)->and($second->before_quantity)->toBe(10)->and($second->after_value_cents)->toBe(24000);
    expect(app(AccessoryInventoryQuery::class)->cost($p))->toBe('12')->and(PricingSettings::count())->toBe(0);
    Http::assertNothingSent();
});
it('C251 replays the same receipt once and rejects conflicting payloads and stale revisions', function () {
    $u = inventoryAdmin();
    $p = inventoryProduct($u);
    $data = inventoryEntry($p);
    $a = app(ReceiveAccessoryStockAction::class);
    $e = $a->execute($u, $data);
    expect($a->execute($u, $data)->id)->toBe($e->id);
    expect(fn () => $a->execute($u, array_replace($data, ['quantity' => 11])))->toThrow(ValidationException::class);
    expect(fn () => $a->execute($u, array_replace($data, ['request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    expect($p->fresh()->quantity)->toBe(10)->and(AccessoryStockEntry::count())->toBe(1);
});
it('C251 creates products idempotently and rejects duplicate normalized codes', function () {
    $u = inventoryAdmin();
    $data = ['code' => ' usb-c ', 'name' => 'Cable', 'sale_price' => '20', 'request_key' => (string) Str::uuid()];
    $a = app(CreateAccessoryProductAction::class);
    $p = $a->execute($u, $data);
    expect($a->execute($u, $data)->id)->toBe($p->id)->and($p->code)->toBe('USB-C');
    expect(fn () => inventoryProduct($u))->toThrow(ValidationException::class);
    expect(fn () => $a->execute($u, array_replace($data, ['name' => 'Distinto'])))->toThrow(ValidationException::class);
    expect(AccessoryProduct::count())->toBe(1);
});
it('C251 rejects invalid receipts without changing stock', function (array $changes) {
    $u = inventoryAdmin();
    $p = inventoryProduct($u);
    expect(fn () => app(ReceiveAccessoryStockAction::class)->execute($u, inventoryEntry($p, $changes)))->toThrow(ValidationException::class);
    expect($p->fresh()->quantity)->toBe(0)->and(AccessoryStockEntry::count())->toBe(0);
})->with([[['quantity' => 0]], [['quantity' => -1]], [['quantity' => '1.5']], [['unit_cost' => '0']], [['unit_cost' => '1.234']], [['kind' => 'SALE']], [['quantity' => 100001]], [['unit_cost' => '9999999.99']]]);
it('C251 allows initial stock only as the first entry', function () {
    $u = inventoryAdmin();
    $p = inventoryProduct($u);
    $a = app(ReceiveAccessoryStockAction::class);
    $a->execute($u, inventoryEntry($p, ['kind' => 'INITIAL']));
    expect(fn () => $a->execute($u, inventoryEntry($p, ['kind' => 'INITIAL'])))->toThrow(ValidationException::class);
    expect($p->fresh()->quantity)->toBe(10);
});
it('C251 rolls back an interrupted receipt and permits the original retry', function () {
    $u = inventoryAdmin();
    $p = inventoryProduct($u);
    $data = inventoryEntry($p);
    $a = app(ReceiveAccessoryStockAction::class);
    DB::statement("CREATE TRIGGER fail_inventory BEFORE INSERT ON accessory_stock_entries BEGIN SELECT RAISE(ABORT, 'interrupted'); END");
    expect(fn () => $a->execute($u, $data))->toThrow(QueryException::class);
    expect($p->fresh()->quantity)->toBe(0)->and($p->fresh()->revision)->toBe(0);
    DB::statement('DROP TRIGGER fail_inventory');
    $a->execute($u, $data);
    expect($p->fresh()->quantity)->toBe(10);
});
it('C251 denies cashier and inactive writes even with a direct permission', function () {
    $u = inventoryAdmin();
    $p = inventoryProduct($u);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    $other->givePermissionTo('accesorios.inventario.gestionar');
    expect(fn () => app(ReceiveAccessoryStockAction::class)->execute($other, inventoryEntry($p)))->toThrow(AuthorizationException::class);
    $u->forceFill(['is_active' => false])->save();
    expect(fn () => inventoryProduct($u, ['code' => 'OTHER']))->toThrow(AuthorizationException::class);
    expect(AccessoryStockEntry::count())->toBe(0);
});
it('C251 preserves entry history as immutable and distinguishes actors on retries', function () {
    $u = inventoryAdmin();
    $p = inventoryProduct($u);
    $data = inventoryEntry($p);
    $a = app(ReceiveAccessoryStockAction::class);
    $e = $a->execute($u, $data);
    expect(fn () => $e->update(['quantity' => 50]))->toThrow(LogicException::class);
    expect(fn () => $e->delete())->toThrow(LogicException::class);
    $other = User::factory()->create();
    $other->assignRole('administrador');
    expect(fn () => $a->execute($other, $data))->toThrow(AuthorizationException::class);
});
it('C251 uses the stored percentage configuration for a suggestion but never overwrites the confirmed price', function () {
    $u = inventoryAdmin();
    $p = inventoryProduct($u);
    app(ReceiveAccessoryStockAction::class)->execute($u, inventoryEntry($p));
    $s = PricingInput::defaults();
    $s['rules']['ACCESSORY'] = ['mode' => 'PERCENT', 'markup_bps' => 5000];
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    expect(app(AccessoryInventoryQuery::class)->suggestion($u, $p)['price_cents'])->toBe(1500)->and($p->fresh()->sale_price_cents)->toBe(2000);
});

it('C251 averages remaining stock precisely and avoids cumulative cent rounding', function () {
    $c = app(AverageCostCalculator::class);
    $remaining = $c->receive(4, 4000, 10, 1400);
    expect($remaining)->toBe(['quantity' => 14, 'value_cents' => 18000, 'entry_cents' => 14000]);
    expect($c->display(14, 18000))->toBe('12.857143')->and($c->unitCostCeiling(14, 18000))->toBe(1286);
    $q = 0;
    $v = 0;
    foreach ([100, 100, 101] as $cost) {
        $n = $c->receive($q, $v, 1, $cost);
        $q = $n['quantity'];
        $v = $n['value_cents'];
    }
    expect($v)->toBe(301)->and($c->display($q, $v))->toBe('1.003333')->and($c->unitCostCeiling($q, $v))->toBe(101);
});
it('C251 preserves unrelated financial data and keeps new purchases independent from the dollar', function () {
    $u = inventoryAdmin();
    $p = inventoryProduct($u);
    app(ReceiveAccessoryStockAction::class)->execute($u, inventoryEntry($p));
    expect(Loan::count())->toBe(0)->and(LoanMovement::count())->toBe(0)->and(ExchangeQuote::count())->toBe(0);
    Http::assertNothingSent();
});

it('C251 stores category and minimum stock and rejects invalid catalogue limits', function () {
    $u = inventoryAdmin();
    $p = inventoryProduct($u, ['category' => 'Cables', 'minimum_stock' => 5]);
    expect($p->category)->toBe('Cables')->and($p->minimum_stock)->toBe(5);
    expect(fn () => inventoryProduct($u, ['code' => 'BAD', 'minimum_stock' => -1]))->toThrow(ValidationException::class);
    expect(AccessoryProduct::count())->toBe(1);
});

it('C251 rolls back the entry as well when the balance update fails after history creation', function () {
    $u = inventoryAdmin();
    $p = inventoryProduct($u);
    $data = inventoryEntry($p);
    $action = app(ReceiveAccessoryStockAction::class);
    DB::statement("CREATE TRIGGER fail_balance BEFORE UPDATE ON accessory_products BEGIN SELECT RAISE(ABORT, 'interrupted balance'); END");
    expect(fn () => $action->execute($u, $data))->toThrow(QueryException::class);
    expect(AccessoryStockEntry::count())->toBe(0)->and($p->fresh()->stock_value_cents)->toBe(0);
    DB::statement('DROP TRIGGER fail_balance');
    $action->execute($u, $data);
    expect(AccessoryStockEntry::count())->toBe(1)->and($p->fresh()->quantity)->toBe(10);
});
it('C251 preserves a confirmed price when a more expensive purchase raises the cost and exposes the warning', function () {
    $u = inventoryAdmin();
    $p = inventoryProduct($u, ['sale_price' => '10']);
    $a = app(ReceiveAccessoryStockAction::class);
    $a->execute($u, inventoryEntry($p));
    $a->execute($u, inventoryEntry($p, ['unit_cost' => '14']));
    $p->refresh();
    expect(app(AccessoryInventoryQuery::class)->belowCost($p))->toBeTrue()->and($p->sale_price_cents)->toBe(1000)->and($p->stock_value_cents)->toBe(24000);
    expect(fn () => app(AccessoryInventoryQuery::class)->suggestion($u, $p))->toThrow(ValidationException::class);
    expect(AccessoryStockEntry::count())->toBe(2);
});
