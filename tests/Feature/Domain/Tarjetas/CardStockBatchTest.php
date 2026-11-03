<?php

use App\Domain\Tarjetas\Actions\RecordCardStockBatchAction;
use App\Domain\Tarjetas\Models\CardInventoryCommand;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Models\User;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function c161Fixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class]);
    $u = User::factory()->create();
    $u->assignRole('cajera');
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 100000, 'initialized_at' => now(), 'initialized_by' => $u->id]);
    $products = [];
    foreach ([['TIGO', 10], ['TIGO', 20], ['ENTEL', 10], ['ENTEL', 30], ['VIVA', 10]] as [$company,$cut]) {
        $p = CardProduct::create(['company' => $company, 'face_value_cents' => $cut * 100, 'purchase_price_cents' => $cut * 94, 'normal_price_cents' => $cut * 100, 'wholesale_price_cents' => $cut * 95, 'is_active' => true, 'revision' => 1, 'created_by' => $u->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 0]);
        $products[] = $p;
    }

    return [$u, ...$products];
}
it('C161 buys several cuts in one atomic idempotent command', function () {
    [$u,$a,$b] = c161Fixture();
    $data = ['company' => 'TIGO', 'lines' => [['product_id' => $a->id, 'quantity' => 10, 'unit_price' => '9.4'], ['product_id' => $b->id, 'quantity' => 5, 'unit_price' => '18.8']], 'request_key' => (string) Str::uuid()];
    $act = app(RecordCardStockBatchAction::class);
    $act->purchase($u, $data);
    $act->purchase($u, $data);
    expect(CardStore::sole()->cash_balance_cents)->toBe(81200)->and(CardStock::where('card_product_id', $a->id)->sole()->quantity)->toBe(10)->and(CardStock::where('card_product_id', $b->id)->sole()->quantity)->toBe(5)->and(CardInventoryMovement::count())->toBe(2)->and(CardInventoryCommand::count())->toBe(1);
});

it('C161 rejects insufficient aggregate cash before any stock is added', function () {
    [$u,$a,$b] = c161Fixture();
    CardStore::sole()->update(['cash_balance_cents' => 10000]);
    $d = ['company' => 'TIGO', 'lines' => [['product_id' => $a->id, 'quantity' => 10, 'unit_price' => '9.4'], ['product_id' => $b->id, 'quantity' => 1, 'unit_price' => '18.8']], 'request_key' => (string) Str::uuid()];
    expect(fn () => app(RecordCardStockBatchAction::class)->purchase($u, $d))->toThrow(ValidationException::class, 'Contacta con Administración');
    expect(CardStock::sum('quantity'))->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(10000)->and(CardInventoryMovement::count())->toBe(0);
});
it('C161 receives several Entel cuts without spending cash or inventing costs', function () {
    [$u,$a,$b,$e,$f] = c161Fixture();
    $d = ['company' => 'ENTEL', 'lines' => [['product_id' => $e->id, 'quantity' => 50], ['product_id' => $f->id, 'quantity' => 20]], 'request_key' => (string) Str::uuid()];
    $act = app(RecordCardStockBatchAction::class);
    $act->delivery($u, $d);
    $act->delivery($u, $d);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000)->and(CardStock::sum('quantity'))->toBe(70)->and(CardInventoryMovement::whereNotNull('unit_cost_cents')->count())->toBe(0)->and(CardInventoryMovement::count())->toBe(2);
});
it('C161 rejects foreign company inactive cuts and duplicate products atomically', function () {
    [$u,$a,$b,$e] = c161Fixture();
    $act = app(RecordCardStockBatchAction::class);
    $base = ['company' => 'TIGO', 'lines' => [['product_id' => $a->id, 'quantity' => 1, 'unit_price' => '9.4'], ['product_id' => $e->id, 'quantity' => 1, 'unit_price' => '9.4']], 'request_key' => (string) Str::uuid()];
    expect(fn () => $act->purchase($u, $base))->toThrow(ValidationException::class);
    $base['lines'][1]['product_id'] = $a->id;
    expect(fn () => $act->purchase($u, $base))->toThrow(ValidationException::class);
    $a->update(['is_active' => false]);
    $base['lines'] = [$base['lines'][0]];
    expect(fn () => $act->purchase($u, $base))->toThrow(ValidationException::class);
    expect(CardInventoryMovement::count())->toBe(0)->and(CardStock::sum('quantity'))->toBe(0);
});
it('C161 rolls back the entire purchase on interrupted audit and permits the same retry', function () {
    [$u,$a,$b] = c161Fixture();
    $d = ['company' => 'TIGO', 'lines' => [['product_id' => $a->id, 'quantity' => 2, 'unit_price' => '9.4'], ['product_id' => $b->id, 'quantity' => 2, 'unit_price' => '18.8']], 'request_key' => (string) Str::uuid()];
    $act = app(RecordCardStockBatchAction::class);
    DB::unprepared("CREATE TRIGGER c161_stop BEFORE INSERT ON card_inventory_commands BEGIN SELECT RAISE(ABORT,'test interruption'); END");
    expect(fn () => $act->purchase($u, $d))->toThrow(QueryException::class);
    expect(CardStock::sum('quantity'))->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(100000)->and(CardInventoryMovement::count())->toBe(0);
    DB::unprepared('DROP TRIGGER c161_stop');
    $act->purchase($u, $d);
    expect(CardInventoryMovement::count())->toBe(2);
});
it('C161 rejects conflicting retry and another actor without repeating a batch', function () {
    [$u,$a] = c161Fixture();
    $d = ['company' => 'TIGO', 'lines' => [['product_id' => $a->id, 'quantity' => 2, 'unit_price' => '9.4']], 'request_key' => (string) Str::uuid()];
    $act = app(RecordCardStockBatchAction::class);
    $act->purchase($u, $d);
    $changed = $d;
    $changed['lines'][0]['quantity'] = 3;
    expect(fn () => $act->purchase($u, $changed))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    expect(fn () => $act->purchase($other, $d))->toThrow(AuthorizationException::class);
    expect(CardInventoryCommand::count())->toBe(1);
});
it('C161 requires actual initialization and active authorization', function () {
    [$u,$a] = c161Fixture();
    $d = ['company' => 'TIGO', 'lines' => [['product_id' => $a->id, 'quantity' => 1, 'unit_price' => '9.4']], 'request_key' => (string) Str::uuid()];
    $act = app(RecordCardStockBatchAction::class);
    CardStore::sole()->update(['initialized_at' => null]);
    expect(fn () => $act->purchase($u, $d))->toThrow(ValidationException::class);
    $u->forceFill(['is_active' => false])->save();
    expect(fn () => $act->purchase($u, $d))->toThrow(AuthorizationException::class);
});
it('C161 skips empty cuts and rejects invalid selected amounts or quantities', function () {
    [$u,$a,$b] = c161Fixture();
    $act = app(RecordCardStockBatchAction::class);
    $base = ['company' => 'TIGO', 'lines' => [['product_id' => $a->id, 'quantity' => 1, 'unit_price' => '9.4'], ['product_id' => $b->id, 'quantity' => '', 'unit_price' => '']], 'request_key' => (string) Str::uuid()];
    $act->purchase($u, $base);
    expect(CardInventoryMovement::count())->toBe(1);
    foreach ([['quantity' => true], ['quantity' => -1], ['unit_price' => '1e3'], ['unit_price' => '1.001'], ['quantity' => 1000000, 'unit_price' => '999999999.99']] as $bad) {
        $d = $base;
        $d['request_key'] = (string) Str::uuid();
        $d['lines'][0] = array_replace($d['lines'][0], $bad);
        expect(fn () => $act->purchase($u, $d))->toThrow(ValidationException::class);
    }
});
it('C161 pays exactly the remaining cash and accepts Viva without changing catalogue prices', function () {
    [$u,$a,$b,$e,$f,$v] = c161Fixture();
    CardStore::sole()->update(['cash_balance_cents' => 930]);
    app(RecordCardStockBatchAction::class)->purchase($u, ['company' => 'VIVA', 'lines' => [['product_id' => $v->id, 'quantity' => 1, 'unit_price' => '9.3']], 'request_key' => (string) Str::uuid()]);
    expect(CardStore::sole()->cash_balance_cents)->toBe(0)->and($v->fresh()->purchase_price_cents)->toBe(940);
});

it('C162 permits an actual Entel purchase while deliveries remain free of store cash expense', function () {
    [$u,$a,$b,$e] = c161Fixture();
    app(RecordCardStockBatchAction::class)->purchase($u, ['company' => 'ENTEL', 'lines' => [['product_id' => $e->id, 'quantity' => 10, 'unit_price' => '9.17']], 'request_key' => (string) Str::uuid()]);
    expect(CardStore::sole()->cash_balance_cents)->toBe(90830)->and(CardInventoryMovement::sole()->kind)->toBe('PURCHASE');
});

it('C163 remembers only purchase reference prices when explicitly confirmed by the purchasing cashier', function () {
    [$u,$a] = c161Fixture();
    $data = ['company' => 'TIGO', 'lines' => [['product_id' => $a->id, 'quantity' => 2, 'unit_price' => '9.5']], 'remember_prices' => true, 'price_revisions' => [$a->id => $a->revision], 'request_key' => (string) Str::uuid()];
    $act = app(RecordCardStockBatchAction::class);
    $act->purchase($u, $data);
    $act->purchase($u, $data);
    expect($a->fresh()->purchase_price_cents)->toBe(950)->and($a->fresh()->normal_price_cents)->toBe(1000)->and($a->fresh()->wholesale_price_cents)->toBe(950)->and($a->fresh()->revision)->toBe(2)->and(CardInventoryMovement::count())->toBe(1)->and(CardStore::sole()->cash_balance_cents)->toBe(98100);
});

it('C163 keeps the previous reference when declined and refuses stale reference replacement', function () {
    [$u,$a] = c161Fixture();
    $base = ['company' => 'TIGO', 'lines' => [['product_id' => $a->id, 'quantity' => 1, 'unit_price' => '9.5']], 'request_key' => (string) Str::uuid()];
    $act = app(RecordCardStockBatchAction::class);
    $act->purchase($u, $base);
    expect($a->fresh()->purchase_price_cents)->toBe(940);
    $a->update(['purchase_price_cents' => 960, 'revision' => 2]);
    $data = array_replace($base, ['remember_prices' => true, 'price_revisions' => [$a->id => 1], 'request_key' => (string) Str::uuid()]);
    expect(fn () => $act->purchase($u, $data))->toThrow(ValidationException::class);
    expect(CardInventoryMovement::count())->toBe(1)->and($a->fresh()->purchase_price_cents)->toBe(960);
});
it('C163 rolls back reference and stock changes when the purchase audit fails', function () {
    [$u,$a] = c161Fixture();
    $data = ['company' => 'TIGO', 'lines' => [['product_id' => $a->id, 'quantity' => 1, 'unit_price' => '9.5']], 'remember_prices' => true, 'price_revisions' => [$a->id => 1], 'request_key' => (string) Str::uuid()];
    DB::unprepared("CREATE TRIGGER c163_fail BEFORE INSERT ON card_inventory_commands BEGIN SELECT RAISE(ABORT,'test interruption'); END");
    expect(fn () => app(RecordCardStockBatchAction::class)->purchase($u, $data))->toThrow(QueryException::class);
    expect($a->fresh()->purchase_price_cents)->toBe(940)->and($a->fresh()->revision)->toBe(1)->and(CardStock::sum('quantity'))->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(100000);
});

it('C166 receives administration cards of every company with zero cash and idempotent history', function () {
    [$u,$a,$b,$e,$f,$v] = c161Fixture();
    CardStore::sole()->update(['cash_balance_cents' => 0]);
    foreach ([$a, $e, $v] as $product) {
        $data = ['company' => $product->company, 'lines' => [['product_id' => $product->id, 'quantity' => 7]], 'reference' => 'Entrega Administración turno mañana', 'request_key' => (string) Str::uuid()];
        $first = app(RecordCardStockBatchAction::class)->delivery($u, $data);
        expect(app(RecordCardStockBatchAction::class)->delivery($u, $data))->toBe($first);
    }
    expect(CardStock::sum('quantity'))->toBe(21)->and(CardStore::sole()->cash_balance_cents)->toBe(0)->and(CardInventoryMovement::count())->toBe(3)->and(CardInventoryCommand::count())->toBe(3);
    foreach (CardInventoryMovement::all() as $movement) {
        expect($movement->kind)->toBe('ADMIN_DELIVERY')->and($movement->cash_delta_cents)->toBe(0)->and($movement->user_id)->toBe($u->id)->and($movement->reference)->toBe('Entrega Administración turno mañana')->and($movement->unit_cost_cents)->toBeNull();
    }
});

it('C166 rejects mixed companies in an administration delivery without partial records', function () {
    [$u,$a,$b,$e] = c161Fixture();
    expect(fn () => app(RecordCardStockBatchAction::class)->delivery($u, ['company' => 'TIGO', 'lines' => [['product_id' => $a->id, 'quantity' => 5], ['product_id' => $e->id, 'quantity' => 5]], 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(CardStock::sum('quantity'))->toBe(0)->and(CardInventoryMovement::count())->toBe(0)->and(CardInventoryCommand::count())->toBe(0);
});
