<?php

use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\ReceiveEntelCardsAction;
use App\Domain\Tarjetas\Actions\RecordCardPurchaseAction;
use App\Domain\Tarjetas\Actions\SaveCardProductAction;
use App\Domain\Tarjetas\Calculators\CardInitialCountPreview;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Queries\CardStoreInventoryQuery;
use App\Models\User;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function c143Person(string $role = 'administrador'): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class]);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}
function c143Product(User $admin, string $company = 'TIGO', string $face = '10'): CardProduct
{
    return app(SaveCardProductAction::class)->execute($admin, ['company' => $company, 'face_value' => $face, 'purchase_price' => '9.40', 'normal_price' => '10', 'wholesale_price' => '9.50', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
}
function c143Initialize(User $admin, string $cash = '1000', array $stocks = []): CardStore
{
    return app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => $cash, 'stocks' => $stocks, 'request_key' => (string) Str::uuid()]);
}
it('C143 records a Tigo purchase with applied cost and atomically reduces only Tarjetas cash', function () {
    $admin = c143Person();
    $cashier = c143Person('cajera');
    $product = c143Product($admin);
    c143Initialize($admin);
    $data = ['product_id' => $product->id, 'quantity' => 50, 'unit_price' => '9.40', 'reference' => 'Proveedor de prueba', 'request_key' => (string) Str::uuid()];
    $purchase = app(RecordCardPurchaseAction::class)->execute($cashier, $data);
    expect($purchase->quantity)->toBe(50)->and($purchase->cash_delta_cents)->toBe(-47000)
        ->and(CardStore::sole()->cash_balance_cents)->toBe(53000)->and(CardStock::where('card_product_id', $product->id)->sole()->quantity)->toBe(50);
    expect(app(RecordCardPurchaseAction::class)->execute($cashier, $data)->id)->toBe($purchase->id);
    expect($product->fresh()->purchase_price_cents)->toBe(940);
    $this->assertDatabaseCount('cash_box_operations', 0);
});
it('C143 receives Entel without touching cash and preserves unknown cost as null', function () {
    $admin = c143Person();
    $cashier = c143Person('cajera');
    $product = c143Product($admin, 'ENTEL');
    c143Initialize($admin, '0');
    $data = ['product_id' => $product->id, 'quantity' => 200, 'reference' => 'Entrega de Administración', 'request_key' => (string) Str::uuid()];
    $receipt = app(ReceiveEntelCardsAction::class)->execute($cashier, $data);
    expect($receipt->cash_delta_cents)->toBe(0)->and($receipt->unit_cost_cents)->toBeNull()
        ->and(CardStore::sole()->cash_balance_cents)->toBe(0)->and(CardStock::where('card_product_id', $product->id)->sole()->quantity)->toBe(200);
    expect(app(ReceiveEntelCardsAction::class)->execute($cashier, $data)->id)->toBe($receipt->id);
});
it('C143 rejects insufficient cash and does not leave a partial stock movement', function () {
    $admin = c143Person();
    $product = c143Product($admin);
    c143Initialize($admin, '10');
    $before = CardInventoryMovement::count();
    expect(fn () => app(RecordCardPurchaseAction::class)->execute($admin, ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '9.40', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(CardStock::where('card_product_id', $product->id)->sole()->quantity)->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(1000)
        ->and(CardInventoryMovement::count())->toBe($before);
});

it('C143 preserves all three prices and snapshots when a purchase uses a different applied cost', function () {
    $admin = c143Person();
    $p = c143Product($admin);
    c143Initialize($admin, '100');
    $m = app(RecordCardPurchaseAction::class)->execute($admin, ['product_id' => $p->id, 'quantity' => 2, 'unit_price' => '9.35', 'request_key' => (string) Str::uuid()]);
    expect($m->total_cost_cents)->toBe(1870)->and($m->product_snapshot['purchase_price_cents'])->toBe(940);
    expect($p->fresh()->only(['purchase_price_cents', 'normal_price_cents', 'wholesale_price_cents']))->toBe(['purchase_price_cents' => 940, 'normal_price_cents' => 1000, 'wholesale_price_cents' => 950]);
    expect(CardStore::sole()->cash_balance_cents)->toBe(8130);
});
it('C143 initializes actual cash and counted cards once without treating initial cards as a purchase', function () {
    $admin = c143Person();
    $p = c143Product($admin, 'ENTEL');
    $key = (string) Str::uuid();
    $data = ['cash_amount' => '3500', 'stocks' => [['product_id' => $p->id, 'quantity' => 100]], 'request_key' => $key];
    $store = app(InitializeCardStoreAction::class)->execute($admin, $data);
    expect($store->cash_balance_cents)->toBe(350000)->and(CardStock::where('card_product_id', $p->id)->sole()->quantity)->toBe(100);
    expect(app(InitializeCardStoreAction::class)->execute($admin, $data)->id)->toBe($store->id);
    expect(fn () => app(InitializeCardStoreAction::class)->execute($admin, [...$data, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(CardInventoryMovement::where('kind', 'INITIAL_STOCK')->sole()->unit_cost_cents)->toBeNull();
    expect(CardInventoryMovement::count())->toBe(2);
});
it('C143 does not authorize purchases or deliveries before a real initial count', function () {
    $admin = c143Person();
    $p = c143Product($admin);
    $e = c143Product($admin, 'ENTEL');
    expect(fn () => app(RecordCardPurchaseAction::class)->execute($admin, ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '9.40', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(fn () => app(ReceiveEntelCardsAction::class)->execute($admin, ['product_id' => $e->id, 'quantity' => 1, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(CardStore::sole()->initialized_at)->toBeNull()->and(CardInventoryMovement::count())->toBe(0);
});
it('C143 refuses a missing product with an actionable validation error and no partial state', function () {
    $admin = c143Person();
    c143Initialize($admin);
    expect(fn () => app(RecordCardPurchaseAction::class)->execute($admin, ['product_id' => 99999, 'quantity' => 1, 'unit_price' => '9.40', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000)->and(CardInventoryMovement::where('kind', 'PURCHASE')->count())->toBe(0);
});
it('C166 permits paid Entel purchases and administration deliveries of Tigo', function () {
    $admin = c143Person();
    $t = c143Product($admin);
    $e = c143Product($admin, 'ENTEL');
    c143Initialize($admin);
    app(RecordCardPurchaseAction::class)->execute($admin, ['product_id' => $e->id, 'quantity' => 1, 'unit_price' => '9.40', 'request_key' => (string) Str::uuid()]);
    app(ReceiveEntelCardsAction::class)->execute($admin, ['product_id' => $t->id, 'quantity' => 1, 'request_key' => (string) Str::uuid()]);
    expect(CardInventoryMovement::count())->toBe(3)->and(CardStore::sole()->cash_balance_cents)->toBe(99060);
});
it('C143 prevents cashier initialization catalog creation and price edits even through domain calls', function () {
    $admin = c143Person();
    $cashier = c143Person('cajera');
    $p = c143Product($admin);
    expect(fn () => c143Initialize($cashier))->toThrow(AuthorizationException::class);
    expect(fn () => c143Product($cashier, 'VIVA'))->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveCardProductAction::class)->execute($cashier, ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '8', 'normal_price' => '9', 'wholesale_price' => '8.50', 'is_active' => true, 'expected_revision' => 1, 'request_key' => (string) Str::uuid()], $p))->toThrow(AuthorizationException::class);
    expect($p->fresh()->normal_price_cents)->toBe(1000);
});
it('C143 allows an authorized encargado to add a product but not change existing prices', function () {
    $manager = c143Person('encargado');
    $p = c143Product($manager, 'VIVA');
    expect($p->created_by)->toBe($manager->id);
    expect(fn () => app(SaveCardProductAction::class)->execute($manager, [], $p))->toThrow(AuthorizationException::class);
});
it('C143 blocks contradictory replay keys and another users replay without duplicating a purchase', function () {
    $admin = c143Person();
    $other = c143Person('cajera');
    $p = c143Product($admin);
    c143Initialize($admin);
    $data = ['product_id' => $p->id, 'quantity' => 5, 'unit_price' => '9.40', 'request_key' => (string) Str::uuid()];
    app(RecordCardPurchaseAction::class)->execute($admin, $data);
    expect(fn () => app(RecordCardPurchaseAction::class)->execute($admin, [...$data, 'quantity' => 6]))->toThrow(ValidationException::class);
    expect(fn () => app(RecordCardPurchaseAction::class)->execute($other, $data))->toThrow(AuthorizationException::class);
    expect(CardInventoryMovement::where('kind', 'PURCHASE')->count())->toBe(1);
});
it('C143 uses the remaining cash after another purchase and allows an exact zero balance', function () {
    $admin = c143Person();
    $b = c143Person('cajera');
    $p = c143Product($admin);
    c143Initialize($admin, '18.80');
    app(RecordCardPurchaseAction::class)->execute($admin, ['product_id' => $p->id, 'quantity' => 2, 'unit_price' => '9.40', 'request_key' => (string) Str::uuid()]);
    expect(CardStore::sole()->cash_balance_cents)->toBe(0);
    expect(fn () => app(RecordCardPurchaseAction::class)->execute($b, ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '9.40', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(CardStock::where('card_product_id', $p->id)->sole()->quantity)->toBe(2);
});
it('C143 rejects malformed money without creating a partial product', function (string $price) {
    $admin = c143Person();
    expect(fn () => app(SaveCardProductAction::class)->execute($admin, ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => $price, 'normal_price' => '10', 'wholesale_price' => '9.50', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(CardProduct::count())->toBe(0);
})->with(['negative' => '-1', 'too precise' => '9.999', 'scientific' => '1e2', 'empty' => '', 'oversized' => '1000000000']);
it('C143 rejects stale price changes and preserves identity and historical movement costs', function () {
    $admin = c143Person();
    $p = c143Product($admin);
    c143Initialize($admin);
    $m = app(RecordCardPurchaseAction::class)->execute($admin, ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '9.40', 'request_key' => (string) Str::uuid()]);
    $data = ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '9.50', 'normal_price' => '10.20', 'wholesale_price' => '9.80', 'is_active' => true, 'expected_revision' => 1, 'request_key' => (string) Str::uuid()];
    app(SaveCardProductAction::class)->execute($admin, $data, $p);
    expect($m->fresh()->product_snapshot['normal_price_cents'])->toBe(1000)->and($m->fresh()->unit_cost_cents)->toBe(940);
    expect(fn () => app(SaveCardProductAction::class)->execute($admin, [...$data, 'request_key' => (string) Str::uuid()], $p))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCardProductAction::class)->execute($admin, [...$data, 'face_value' => '20', 'expected_revision' => 2, 'request_key' => (string) Str::uuid()], $p))->toThrow(ValidationException::class);
    expect(fn () => app(SaveCardProductAction::class)->execute($admin, [...$data, 'is_active' => false, 'expected_revision' => 2, 'request_key' => (string) Str::uuid()], $p))->toThrow(ValidationException::class);
});
it('C143 rolls stock cash and movement back if command audit fails and retries once', function () {
    $admin = c143Person();
    $p = c143Product($admin);
    c143Initialize($admin);
    $data = ['product_id' => $p->id, 'quantity' => 5, 'unit_price' => '9.40', 'request_key' => (string) Str::uuid()];
    $fail = true;
    DB::listen(function ($query) use (&$fail): void {
        if ($fail && str_starts_with($query->sql, 'insert into "card_inventory_commands"') && in_array('PURCHASE', $query->bindings, true)) {
            $fail = false;
            throw new RuntimeException('Interrupción de prueba');
        }
    });
    expect(fn () => app(RecordCardPurchaseAction::class)->execute($admin, $data))->toThrow(RuntimeException::class);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000)->and(CardStock::where('card_product_id', $p->id)->sole()->quantity)->toBe(0)->and(CardInventoryMovement::where('kind', 'PURCHASE')->count())->toBe(0);
    $m = app(RecordCardPurchaseAction::class)->execute($admin, $data);
    app(RecordCardPurchaseAction::class)->execute($admin, $data);
    expect(CardStore::sole()->cash_balance_cents)->toBe(95300)->and(CardInventoryMovement::where('kind', 'PURCHASE')->count())->toBe(1);
});
it('C143 does not allow deleting or rewriting an inventory movement', function () {
    $admin = c143Person();
    c143Initialize($admin);
    $m = CardInventoryMovement::sole();
    expect(fn () => $m->update(['cash_after_cents' => 999999]))->toThrow(LogicException::class);
    expect(fn () => $m->delete())->toThrow(LogicException::class);
    expect($m->fresh()->cash_after_cents)->toBe(100000);
});
it('C143 revokes operational access immediately for an inactive or individually restricted cashier', function () {
    $admin = c143Person();
    $b = c143Person('cajera');
    $p = c143Product($admin);
    c143Initialize($admin);
    $data = ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '9.40', 'request_key' => (string) Str::uuid()];
    $b->restrictedPermissions()->attach(Permission::findByName('tarjetas.compras.registrar', 'web'));
    expect(fn () => app(RecordCardPurchaseAction::class)->execute($b, $data))->toThrow(AuthorizationException::class);
    $b->forceFill(['is_active' => false])->save();
    expect(fn () => app(CardStoreInventoryQuery::class)->products($b))->toThrow(AuthorizationException::class);
});
it('C143 keeps supplied cash and stock deltas out of the persisted purchase calculation', function () {
    $admin = c143Person();
    $p = c143Product($admin);
    c143Initialize($admin);
    $m = app(RecordCardPurchaseAction::class)->execute($admin, ['product_id' => $p->id, 'quantity' => 2, 'unit_price' => '9.40', 'cash_delta_cents' => 10000000, 'stock_after' => 999999, 'request_key' => (string) Str::uuid()]);
    expect($m->cash_delta_cents)->toBe(-1880)->and($m->stock_after)->toBe(2)->and(CardStore::sole()->cash_balance_cents)->toBe(98120);
});

it('C143 rolls an invalid initial stock row back with its cash and all earlier rows', function () {
    $admin = c143Person();
    $p = c143Product($admin);
    expect(fn () => c143Initialize($admin, '1000', [['product_id' => $p->id, 'quantity' => 100], ['product_id' => 999999, 'quantity' => 2]]))->toThrow(ValidationException::class);
    expect(CardStore::sole()->initialized_at)->toBeNull()->and(CardStore::sole()->cash_balance_cents)->toBe(0)
        ->and(CardStock::where('card_product_id', $p->id)->sole()->quantity)->toBe(0)->and(CardInventoryMovement::count())->toBe(0);
});
it('C143 refuses duplicate catalogue identities and inactive products without altering stock', function () {
    $admin = c143Person();
    $p = c143Product($admin);
    expect(fn () => c143Product($admin))->toThrow(ValidationException::class);
    app(SaveCardProductAction::class)->execute($admin, ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '9.40', 'normal_price' => '10', 'wholesale_price' => '9.50', 'is_active' => false, 'expected_revision' => 1, 'request_key' => (string) Str::uuid()], $p);
    c143Initialize($admin);
    expect(fn () => app(RecordCardPurchaseAction::class)->execute($admin, ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '9.40', 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(CardProduct::count())->toBe(1)->and(CardInventoryMovement::where('kind', 'PURCHASE')->count())->toBe(0);
});
it('C143 reuses a completed UUID with equivalent monetary formatting and rejects invalid quantities', function () {
    $admin = c143Person();
    $p = c143Product($admin);
    c143Initialize($admin);
    $data = ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '9.4', 'request_key' => (string) Str::uuid()];
    $m = app(RecordCardPurchaseAction::class)->execute($admin, $data);
    expect(app(RecordCardPurchaseAction::class)->execute($admin, [...$data, 'unit_price' => '9.40'])->id)->toBe($m->id);
    foreach ([0, -1, '2.5', 1000001] as $quantity) {
        expect(fn () => app(RecordCardPurchaseAction::class)->execute($admin, [...$data, 'quantity' => $quantity, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    }
    expect(CardInventoryMovement::where('kind', 'PURCHASE')->count())->toBe(1);
});
it('C143 permission seeding preserves individually configured role restrictions on rerun', function () {
    $cashier = c143Person('cajera');
    $role = Role::findByName('cajera', 'web');
    $role->revokePermissionTo('tarjetas.compras.registrar');
    test()->seed(TarjetasPermissionsSeeder::class);
    expect($cashier->fresh()->can('tarjetas.compras.registrar'))->toBeFalse()->and($cashier->fresh()->can('tarjetas.inventario.ver'))->toBeTrue();
});
it('C143 product reads eagerly load inventory instead of querying each row', function () {
    $admin = c143Person();
    for ($i = 1; $i <= 15; $i++) {
        c143Product($admin, 'TIGO', (string) $i);
    }
    $queries = [];
    DB::listen(function ($event) use (&$queries): void {
        $queries[] = $event->sql;
    });
    $products = app(CardStoreInventoryQuery::class)->products($admin)->get();
    foreach ($products as $p) {
        expect($p->stock->quantity)->toBe(0);
    }
    $reads = array_filter($queries, fn ($sql) => str_starts_with(strtolower($sql), 'select') && str_contains($sql, '"card_stocks"'));
    expect(count($reads))->toBe(1);
});

it('C152 previews quantities without dropping zeros or accepting invalid counts', function () {
    $preview = app(CardInitialCountPreview::class);
    expect($preview->units([['quantity' => '5000'], ['quantity' => 20], ['quantity' => '']]))->toBe(5020)
        ->and($preview->units([]))->toBe(0)
        ->and($preview->units([['quantity' => -1]]))->toBeNull()
        ->and($preview->units([['quantity' => '2.5']]))->toBeNull()
        ->and($preview->units([['quantity' => 1000001]]))->toBeNull()
        ->and($preview->units(['invalid']))->toBeNull();
});
