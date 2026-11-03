<?php

use App\Domain\Accesorios\Actions\CreateAccessoryProductAction;
use App\Domain\Accesorios\Actions\ReceiveAccessoryStockAction;
use App\Domain\Accesorios\Actions\RegisterAccessorySaleAction;
use App\Domain\Accesorios\Actions\UpdateAccessoryPriceAction;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryPriceChange;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\AccessoryStockEntry;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Accesorios\Models\CommercialRules;
use App\Domain\Accesorios\Models\ExchangeQuote;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Models\User;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function saleAdmin(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class]);
    Http::preventStrayRequests();
    $u = User::factory()->create();
    $u->assignRole('administrador');

    return $u;
}
function saleProduct(User $u, int $quantity = 10, string $cost = '10', string $price = '20'): AccessoryProduct
{
    $p = app(CreateAccessoryProductAction::class)->execute($u, ['code' => 'SKU-'.Str::upper(Str::random(8)), 'name' => 'Cable USB', 'sale_price' => $price, 'request_key' => (string) Str::uuid()]);
    app(ReceiveAccessoryStockAction::class)->execute($u, ['product_id' => $p->id, 'quantity' => $quantity, 'unit_cost' => $cost, 'kind' => 'INITIAL', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);

    return $p->refresh();
}
function salePayload(array $quantities): array
{
    $lines = [];
    foreach ($quantities as $id => $quantity) {
        $p = AccessoryProduct::findOrFail($id);
        $lines[] = ['product_id' => $id, 'quantity' => $quantity, 'expected_revision' => $p->revision, 'expected_price_cents' => $p->sale_price_cents];
    }

    return ['lines' => $lines, 'payment_method' => 'CASH', 'cash_received' => true, 'request_key' => (string) Str::uuid()];
}
it('C252 atomically sells multiple accessories at confirmed prices and records their average cost and own cash', function () {
    $u = saleAdmin();
    $p = saleProduct($u);
    $q = saleProduct($u, 5, '4', '8');
    $sale = app(RegisterAccessorySaleAction::class)->execute($u, salePayload([$p->id => 3, $q->id => 2]));
    expect($sale->total_cents)->toBe(7600)->and($sale->cost_cents)->toBe(3800)->and($sale->quantity)->toBe(5)->and($sale->actor_name)->toBe($u->name);
    expect($p->fresh()->quantity)->toBe(7)->and($p->fresh()->stock_value_cents)->toBe(7000)->and($q->fresh()->quantity)->toBe(3);
    expect(CommercialFund::firstOrFail()->balance_cents)->toBe(7600)->and(CommercialMovement::count())->toBe(1)->and(AccessorySaleLine::count())->toBe(2);
    expect(CommercialMovement::first()->sale_id)->toBe($sale->id)->and(AccessoryStockEntry::count())->toBe(2);
    Http::assertNothingSent();
});
it('C252 uses only remaining stock cost for a later purchase and preserves previous sale snapshots', function () {
    $u = saleAdmin();
    $p = saleProduct($u);
    $a = app(RegisterAccessorySaleAction::class);
    $sale = $a->execute($u, salePayload([$p->id => 6]));
    app(ReceiveAccessoryStockAction::class)->execute($u, ['product_id' => $p->id, 'quantity' => 10, 'unit_cost' => '14', 'kind' => 'PURCHASE', 'expected_revision' => $p->fresh()->revision, 'request_key' => (string) Str::uuid()]);
    expect($p->fresh()->quantity)->toBe(14)->and($p->fresh()->stock_value_cents)->toBe(18000);
    $next = $a->execute($u, salePayload([$p->id => 7]));
    expect($next->cost_cents)->toBe(9000)->and($p->fresh()->stock_value_cents)->toBe(9000)->and($sale->lines()->first()->cost_cents)->toBe(6000);
});
it('C252 distributes fractional cents and consumes all residual cost when stock is exhausted', function () {
    $u = saleAdmin();
    $p = saleProduct($u, 2, '1', '2');
    app(ReceiveAccessoryStockAction::class)->execute($u, ['product_id' => $p->id, 'quantity' => 1, 'unit_cost' => '1.01', 'kind' => 'PURCHASE', 'expected_revision' => $p->revision, 'request_key' => (string) Str::uuid()]);
    $costs = [];
    for ($i = 0; $i < 3; $i++) {
        $costs[] = app(RegisterAccessorySaleAction::class)->execute($u, salePayload([$p->id => 1]))->cost_cents;
    }
    expect($costs)->toBe([100, 101, 100])->and(array_sum($costs))->toBe(301)->and($p->fresh()->quantity)->toBe(0)->and($p->fresh()->stock_value_cents)->toBe(0);
});
it('C252 blocks a unit price below the exact average even when rounded allocation would hide the loss', function () {
    $u = saleAdmin();
    $p = saleProduct($u, 2, '1', '1');
    app(ReceiveAccessoryStockAction::class)->execute($u, ['product_id' => $p->id, 'quantity' => 1, 'unit_cost' => '1.01', 'kind' => 'PURCHASE', 'expected_revision' => $p->revision, 'request_key' => (string) Str::uuid()]);
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, salePayload([$p->id => 1])))->toThrow(ValidationException::class);
    expect(AccessorySale::count())->toBe(0)->and(CommercialMovement::count())->toBe(0)->and($p->fresh()->quantity)->toBe(3);
});
it('C252 blocks any line below cost without letting another profitable line compensate it', function () {
    $u = saleAdmin();
    $p = saleProduct($u, 5, '12', '10');
    $q = saleProduct($u, 5, '1', '100');
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, salePayload([$p->id => 1, $q->id => 1])))->toThrow(ValidationException::class);
    expect($q->fresh()->quantity)->toBe(5)->and(AccessorySale::count())->toBe(0)->and(CommercialFund::count())->toBe(0);
});
it('C252 retries a fully sold basket once and rejects different payloads or actors using that UUID', function () {
    $u = saleAdmin();
    $p = saleProduct($u, 1);
    $data = salePayload([$p->id => 1]);
    $a = app(RegisterAccessorySaleAction::class);
    $sale = $a->execute($u, $data);
    expect($a->execute($u, $data)->id)->toBe($sale->id)->and($p->fresh()->quantity)->toBe(0)->and(CommercialMovement::count())->toBe(1);
    $changed = $data;
    $changed['lines'][0]['quantity'] = 2;
    expect(fn () => $a->execute($u, $changed))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('cajera');
    expect(fn () => $a->execute($other, $data))->toThrow(AuthorizationException::class);
});
it('C252 rejects stale stock revisions and changed catalogue prices before charging', function () {
    $u = saleAdmin();
    $p = saleProduct($u);
    $data = salePayload([$p->id => 1]);
    app(RegisterAccessorySaleAction::class)->execute($u, salePayload([$p->id => 1]));
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, $data))->toThrow(ValidationException::class);
    $data = salePayload([$p->id => 1]);
    $p->forceFill(['sale_price_cents' => 3000])->save();
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, $data))->toThrow(ValidationException::class);
    expect(AccessorySale::count())->toBe(1)->and(CommercialFund::first()->balance_cents)->toBe(2000);
});
it('C252 refuses missing cash confirmation, unsupported payments and invalid baskets', function (array $changes) {
    $u = saleAdmin();
    $p = saleProduct($u);
    $data = array_replace(salePayload([$p->id => 1]), $changes);
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, $data))->toThrow(ValidationException::class);
    expect(AccessorySale::count())->toBe(0)->and($p->fresh()->quantity)->toBe(10);
})->with([[['cash_received' => false]], [['payment_method' => 'QR']], [['lines' => []]], [['request_key' => 'not-a-uuid']]]);
it('C252 rejects invalid line quantities, duplicate products and unavailable stock', function (string $case) {
    $u = saleAdmin();
    $p = saleProduct($u);
    $data = salePayload([$p->id => 1]);
    if ($case === 'duplicate') {
        $data['lines'][] = $data['lines'][0];
    } else {
        $data['lines'][0]['quantity'] = match ($case) {
            'zero' => 0,'negative' => -1,'decimal' => '1.5','excess' => 11
        };
    }
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, $data))->toThrow(ValidationException::class);
    expect(AccessorySaleLine::count())->toBe(0)->and($p->fresh()->quantity)->toBe(10);
})->with(['zero', 'negative', 'decimal', 'excess', 'duplicate']);
it('C252 rolls back sale stock and cash after an interruption and then accepts exactly one retry', function () {
    $u = saleAdmin();
    $p = saleProduct($u);
    $data = salePayload([$p->id => 2]);
    $a = app(RegisterAccessorySaleAction::class);
    DB::statement("CREATE TRIGGER fail_sale_cash BEFORE INSERT ON accessory_commercial_movements BEGIN SELECT RAISE(ABORT, 'cash interrupted'); END");
    expect(fn () => $a->execute($u, $data))->toThrow(QueryException::class);
    expect(AccessorySale::count())->toBe(0)->and(AccessorySaleLine::count())->toBe(0)->and(CommercialFund::count())->toBe(0)->and($p->fresh()->quantity)->toBe(10);
    DB::statement('DROP TRIGGER fail_sale_cash');
    $a->execute($u, $data);
    $a->execute($u, $data);
    expect(AccessorySale::count())->toBe(1)->and(CommercialFund::first()->balance_cents)->toBe(4000)->and($p->fresh()->quantity)->toBe(8);
});
it('C252 cashier can sell and see operational data without exposing acquisition cost or profit', function () {
    $u = saleAdmin();
    $p = saleProduct($u);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $a = app(RegisterAccessorySaleAction::class);
    $sale = $a->execute($cashier, salePayload([$p->id => 1]));
    $detail = app(AccessorySaleQuery::class)->detail($cashier, $sale);
    expect($detail)->not->toHaveKey('cost_cents')->not->toHaveKey('profit_cents');
    expect($detail['lines'][0])->not->toHaveKey('cost_cents');
    expect($sale->user_id)->toBe($cashier->id)->and(app(AccessorySaleQuery::class)->detail($u, $sale)['cost_cents'])->toBe(1000);
    $cashier->forceFill(['is_active' => false])->save();
    expect(fn () => $a->execute($cashier, salePayload([$p->id => 1])))->toThrow(AuthorizationException::class);
});
it('C252 keeps sale lines and cash movements immutable and leaves existing pricing and other funds untouched', function () {
    $u = saleAdmin();
    $p = saleProduct($u);
    $sale = app(RegisterAccessorySaleAction::class)->execute($u, salePayload([$p->id => 1]));
    expect(fn () => $sale->update(['total_cents' => 1]))->toThrow(LogicException::class);
    expect(fn () => $sale->lines()->first()->delete())->toThrow(LogicException::class);
    expect(fn () => CommercialMovement::first()->update(['amount_cents' => 1]))->toThrow(LogicException::class);
    expect(PricingSettings::count())->toBe(0)->and(ExchangeQuote::count())->toBe(0)->and(LoanMovement::count())->toBe(0);
    Http::assertNothingSent();
});

it('C252 lets Administration correct a blocked price with the saved percentage without rewriting stock cost or settings', function () {
    $u = saleAdmin();
    $p = saleProduct($u, 10, '12', '10');
    $settings = PricingInput::defaults();
    $settings['rules']['ACCESSORY'] = ['mode' => 'PERCENT', 'markup_bps' => 5000];
    $settings['revision'] = 4;
    PricingSettings::create(['code' => 'GENERAL'] + $settings);
    $data = ['product_id' => $p->id, 'price_mode' => 'PERCENT', 'expected_revision' => $p->revision, 'expected_pricing_revision' => 4, 'request_key' => (string) Str::uuid()];
    $a = app(UpdateAccessoryPriceAction::class);
    $change = $a->execute($u, $data);
    expect($change->new_price_cents)->toBe(1800)->and($change->old_price_cents)->toBe(1000)->and($change->pricing_revision)->toBe(4)->and($a->execute($u, $data)->id)->toBe($change->id);
    expect($p->fresh()->sale_price_cents)->toBe(1800)->and($p->fresh()->stock_value_cents)->toBe(12000)->and(PricingSettings::first()->revision)->toBe(4);
    $sale = app(RegisterAccessorySaleAction::class)->execute($u, salePayload([$p->id => 1]));
    expect($sale->total_cents)->toBe(1800);
});
it('C252 manual administrative price changes cannot bypass cost or cashier restrictions', function () {
    $u = saleAdmin();
    $p = saleProduct($u);
    $a = app(UpdateAccessoryPriceAction::class);
    $data = ['product_id' => $p->id, 'price_mode' => 'MANUAL', 'manual_price' => '9.99', 'expected_revision' => $p->revision, 'request_key' => (string) Str::uuid()];
    expect(fn () => $a->execute($u, $data))->toThrow(ValidationException::class);
    $data['manual_price'] = '15';
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    expect(fn () => $a->execute($cashier, $data))->toThrow(AuthorizationException::class);
    $change = $a->execute($u, $data);
    expect($p->fresh()->sale_price_cents)->toBe(1500);
    expect(fn () => $change->delete())->toThrow(LogicException::class);
    $data['manual_price'] = '16';
    expect(fn () => $a->execute($u, $data))->toThrow(ValidationException::class);
});

it('C252 preserves earlier cash and inventory when the final fund update fails', function () {
    $u = saleAdmin();
    $p = saleProduct($u);
    $a = app(RegisterAccessorySaleAction::class);
    $a->execute($u, salePayload([$p->id => 1]));
    $data = salePayload([$p->id => 2]);
    DB::statement("CREATE TRIGGER fail_final_cash BEFORE UPDATE ON accessory_commercial_funds BEGIN SELECT RAISE(ABORT, 'final cash interrupted'); END");
    expect(fn () => $a->execute($u, $data))->toThrow(QueryException::class);
    expect(AccessorySale::count())->toBe(1)->and(AccessorySaleLine::count())->toBe(1)->and(CommercialMovement::count())->toBe(1)->and(CommercialFund::first()->balance_cents)->toBe(2000)->and($p->fresh()->quantity)->toBe(9);
    DB::statement('DROP TRIGGER fail_final_cash');
    $a->execute($u, $data);
    expect(CommercialFund::first()->balance_cents)->toBe(6000)->and($p->fresh()->quantity)->toBe(7);
});
it('C252 blocks an overflowing cash fund without consuming stock', function () {
    $u = saleAdmin();
    $p = saleProduct($u);
    CommercialFund::create(['code' => 'GENERAL', 'balance_cents' => 999999999, 'revision' => 0]);
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, salePayload([$p->id => 1])))->toThrow(ValidationException::class);
    expect($p->fresh()->quantity)->toBe(10)->and(AccessorySale::count())->toBe(0)->and(CommercialFund::first()->balance_cents)->toBe(999999999);
});
it('C252 rejects a changed percentage configuration before applying a reviewed product price', function () {
    $u = saleAdmin();
    $p = saleProduct($u);
    $s = PricingInput::defaults();
    $s['rules']['ACCESSORY'] = ['mode' => 'PERCENT', 'markup_bps' => 5000];
    $s['revision'] = 2;
    PricingSettings::create(['code' => 'GENERAL'] + $s);
    expect(fn () => app(UpdateAccessoryPriceAction::class)->execute($u, ['product_id' => $p->id, 'price_mode' => 'PERCENT', 'expected_revision' => $p->revision, 'expected_pricing_revision' => 1, 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect($p->fresh()->sale_price_cents)->toBe(2000)->and(AccessoryPriceChange::count())->toBe(0);
});

it('C291 lets a cashier discount a line while freezing list price and collecting only the net amount once', function () {
    $u = saleAdmin();
    CommercialRules::create(['scope' => 'DISCOUNTS', 'rules' => ['phone_max_cents' => 20000, 'ranges' => [['from_cents' => 0, 'amount_cents' => 20000]]], 'revision' => 0]);
    $p = saleProduct($u);
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $data = salePayload([$p->id => 2]);
    $data['lines'][0]['discount'] = '5';
    $action = app(RegisterAccessorySaleAction::class);
    $sale = $action->execute($cashier, $data);
    expect($sale->total_cents)->toBe(3500);
    expect($sale->lines->sole()->unit_price_cents)->toBe(2000);
    expect($sale->lines->sole()->cost_cents)->toBe(2000);
    expect($p->fresh()->sale_price_cents)->toBe(2000);
    expect($p->fresh()->quantity)->toBe(8);
    $receipt = app(AccessorySaleQuery::class)->receipt($cashier, $sale->id);
    expect($receipt['discount_cents'])->toBe(500);
    expect($receipt['subtotal_cents'])->toBe(4000);
    expect($action->execute($cashier, $data)->id)->toBe($sale->id);
    expect(CommercialMovement::count())->toBe(1);
    expect(CommercialFund::sole()->balance_cents)->toBe(3500);
    $data['lines'][0]['discount'] = '6';
    expect(fn () => $action->execute($cashier, $data))->toThrow(ValidationException::class);
});

it('C291 blocks negative and below-cost discounts by line even with other profitable products', function () {
    $u = saleAdmin();
    CommercialRules::create(['scope' => 'DISCOUNTS', 'rules' => ['phone_max_cents' => 20000, 'ranges' => [['from_cents' => 0, 'amount_cents' => 20000]]], 'revision' => 0]);
    $p = saleProduct($u);
    $other = saleProduct($u);
    $data = salePayload([$p->id => 2, $other->id => 1]);
    foreach (['-1', '20.01', '9999'] as $discount) {
        $data['lines'][0]['discount'] = $discount;
        expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, $data))->toThrow(ValidationException::class);
        expect(AccessorySale::count())->toBe(0);
        expect($p->fresh()->quantity)->toBe(10);
    }
    $data['lines'][0]['discount'] = '20';
    expect(app(RegisterAccessorySaleAction::class)->execute($u, $data)->total_cents)->toBe(4000);
});

it('C291 compares discounted accessories against the exact average cost rather than a rounded allocation', function () {
    $u = saleAdmin();
    CommercialRules::create(['scope' => 'DISCOUNTS', 'rules' => ['phone_max_cents' => 20000, 'ranges' => [['from_cents' => 0, 'amount_cents' => 20000]]], 'revision' => 0]);
    $p = saleProduct($u, 3);
    $p->update(['stock_value_cents' => 3001]);
    $data = salePayload([$p->id => 1]);
    $data['lines'][0]['discount'] = '10';
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($u, $data))->toThrow(ValidationException::class);
    expect(AccessorySale::count())->toBe(0);
    $data['lines'][0]['discount'] = '9.99';
    expect(app(RegisterAccessorySaleAction::class)->execute($u, $data)->total_cents)->toBe(1001);
});
