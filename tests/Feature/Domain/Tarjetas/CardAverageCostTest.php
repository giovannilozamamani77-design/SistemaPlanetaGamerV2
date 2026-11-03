<?php

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\ReceiveEntelCardsAction;
use App\Domain\Tarjetas\Actions\RecordCardPurchaseAction;
use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordCardStoreCountAction;
use App\Domain\Tarjetas\Actions\RecordEntelDigitalFundingAction;
use App\Domain\Tarjetas\Actions\RecordEntelRechargeAction;
use App\Domain\Tarjetas\Actions\ResolveCardStoreReserveAction;
use App\Domain\Tarjetas\Actions\SaveCardProductAction;
use App\Domain\Tarjetas\Actions\ValueRemainingCardCostAction;
use App\Domain\Tarjetas\Calculators\CardAverageCostCalculator;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardSaleLine;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreReserve;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Models\EntelDigitalMovement;
use App\Domain\Tarjetas\Models\EntelRecharge;
use App\Domain\Tarjetas\Queries\CardCostsQuery;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Filament\Pages\Tarjetas\CardCosts;
use App\Models\User;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\EntelDigitalPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function c367Fixture(?string $cost = '9.40', int $quantity = 20): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, EntelDigitalPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    $product = app(SaveCardProductAction::class)->execute($admin, ['company' => 'TIGO', 'face_value' => '10', 'purchase_price' => '9.40', 'normal_price' => '10', 'wholesale_price' => '9.50', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '10000', 'stocks' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost]], 'request_key' => (string) Str::uuid()]);

    return [$admin, $cashier, $product];
}
function c367Sale(User $user, CardProduct $product, int $quantity = 1, string $price = '10', ?string $key = null): CardSale
{
    return app(RecordCardSaleAction::class)->execute($user, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price]], 'request_key' => $key ?? (string) Str::uuid()]);
}
function c367Purchase(User $user, CardProduct $product, int $quantity, string $price): void
{
    app(RecordCardPurchaseAction::class)->execute($user, ['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price, 'request_key' => (string) Str::uuid()]);
}
function c367Funding(User $user, string $kind, string $operation, string $credited, ?string $paid = null): EntelDigitalMovement
{
    return app(RecordEntelDigitalFundingAction::class)->execute($user, ['kind' => $kind, 'operation' => $operation, 'credited_amount' => $credited, 'paid_amount' => $paid, 'request_key' => (string) Str::uuid()]);
}
function c367Recharge(User $user, string $kind, string $amount = '100', ?string $key = null): EntelRecharge
{
    return app(RecordEntelRechargeAction::class)->execute($user, ['kind' => $kind, 'amount' => $amount, 'external_confirmed' => true, 'request_key' => $key ?? (string) Str::uuid()]);
}
function c367Value(CardStock|EntelDigitalAccount $record, string $total): array
{
    $physical = $record instanceof CardStock;

    return ['type' => $physical ? 'PHYSICAL' : 'DIGITAL', 'id' => $record->id, 'expected_quantity' => $physical ? $record->quantity : $record->balance_cents, 'expected_revision' => $record->cost_revision, 'total_cost' => $total, 'reason' => 'Costo real respaldado de existencias restantes', 'request_key' => (string) Str::uuid()];
}

it('C367 freezes weighted remaining cost without duplicate stock cash or margins', function () {
    [$admin, $cashier, $p] = c367Fixture();
    c367Purchase($cashier, $p, 20, '9.60');
    expect($p->stock->quantity)->toBe(40)->and($p->stock->cost_value_cents)->toBe(38000);
    $key = (string) Str::uuid();
    $sale = c367Sale($cashier, $p, 10, '10', $key);
    expect($sale->lines->sole()->cost_cents)->toBe(9500)->and($sale->lines->sole()->gross_margin_cents)->toBe(500);
    expect(c367Sale($cashier, $p, 10, '10', $key)->id)->toBe($sale->id);
    expect($p->stock()->sole()->cost_value_cents)->toBe(28500)->and(CardStore::sole()->cash_balance_cents)->toBe(990800);
    c367Purchase($cashier, $p, 10, '11');
    expect($sale->fresh()->lines->sole()->cost_cents)->toBe(9500)->and(CardSale::count())->toBe(1);
    expect($sale->lines->sole()->toArray())->not->toHaveKeys(['cost_cents', 'gross_margin_cents']);
});

it('C367 averages only remaining stock and preserves a negative actual margin without a new sales block', function () {
    [$admin, $cashier, $p] = c367Fixture();
    c367Sale($cashier, $p, 10);
    c367Purchase($cashier, $p, 20, '9.60');
    $line = c367Sale($cashier, $p, 10, '9')->lines->sole();
    expect($line->cost_cents)->toBe(9533)->and($line->gross_margin_cents)->toBe(-533)->and($p->stock()->sole()->cost_value_cents)->toBe(19067);
});

it('C367 keeps unknown costs pending and completes only future valuation without changing cash or earlier sales', function () {
    [$admin, $cashier, $p] = c367Fixture(null);
    $old = c367Sale($cashier, $p, 2)->lines->sole();
    $cash = CardStore::sole()->cash_balance_cents;
    $data = c367Value($p->stock()->sole(), '169.20');
    $action = app(ValueRemainingCardCostAction::class);
    $first = $action->execute($admin, $data);
    expect($action->execute($admin, $data))->toBe($first);
    expect(CardStore::sole()->cash_balance_cents)->toBe($cash)->and($p->stock()->sole()->quantity)->toBe(18);
    expect($old->fresh()->cost_cents)->toBeNull()->and($old->fresh()->gross_margin_cents)->toBeNull();
    expect(c367Sale($cashier, $p)->lines->sole()->cost_cents)->toBe(940);
    $summary = app(CardCostsQuery::class)->margins($admin);
    expect($summary['PHYSICAL'])->toBe(['records' => 2, 'pending' => 1, 'known_margin_cents' => 60]);
    expect(CardInventoryMovement::where('kind', 'COST_VALUATION')->count())->toBe(1);
});

it('C367 does not invent a cost for a mixed unknown pool and recovers after full depletion', function () {
    [$admin, $cashier, $p] = c367Fixture(null, 2);
    c367Purchase($cashier, $p, 2, '9.40');
    expect($p->stock()->sole()->cost_value_cents)->toBeNull();
    expect(c367Sale($cashier, $p, 4)->lines->sole()->cost_cents)->toBeNull();
    expect($p->stock()->sole()->cost_value_cents)->toBe(0);
    c367Purchase($cashier, $p, 2, '9.60');
    expect(c367Sale($cashier, $p)->lines->sole()->cost_cents)->toBe(960);
});

it('C367 values known administrative deliveries without cash and marks unknown deliveries pending', function () {
    [$admin, $cashier, $p] = c367Fixture();
    $cash = CardStore::sole()->cash_balance_cents;
    app(ReceiveEntelCardsAction::class)->execute($cashier, ['product_id' => $p->id, 'quantity' => 2, 'unit_cost' => '9.60', 'request_key' => (string) Str::uuid()]);
    expect($p->stock()->sole()->cost_value_cents)->toBe(20720)->and(CardStore::sole()->cash_balance_cents)->toBe($cash);
    app(ReceiveEntelCardsAction::class)->execute($cashier, ['product_id' => $p->id, 'quantity' => 1, 'request_key' => (string) Str::uuid()]);
    expect($p->stock()->sole()->cost_value_cents)->toBeNull()->and(CardStore::sole()->cash_balance_cents)->toBe($cash);
});

it('C367 keeps credit and packages independent with external customer bonus and exact final residual', function (string $kind, int $customer) {
    [$admin, $cashier] = c367Fixture();
    c367Funding($admin, $kind, 'INITIAL', '0');
    $funding = c367Funding($admin, $kind, 'TOP_UP', '5250', '5000');
    $other = $kind === 'CREDIT' ? 'PACKAGES' : 'CREDIT';
    c367Funding($admin, $other, 'INITIAL', '200');
    $key = (string) Str::uuid();
    $sale = c367Recharge($cashier, $kind, '100', $key);
    expect(c367Recharge($cashier, $kind, '100', $key)->id)->toBe($sale->id);
    expect($sale->cost_cents)->toBe(9524)->and($sale->gross_margin_cents)->toBe(476)->and($sale->customer_total_cents)->toBe($customer);
    $last = c367Recharge($cashier, $kind, '5150');
    expect($last->cost_cents)->toBe(490476)->and($last->gross_margin_cents + $sale->gross_margin_cents)->toBe(25000);
    expect(EntelDigitalAccount::where('kind', $kind)->sole()->cost_value_cents)->toBe(0)->and(EntelDigitalAccount::where('kind', $other)->sole()->balance_cents)->toBe(20000);
    expect($funding->fresh()->paid_cents)->toBe(500000)->and($sale->toArray())->not->toHaveKeys(['cost_cents', 'gross_margin_cents']);
})->with([['CREDIT', 10800], ['PACKAGES', 11500]]);

it('C367 mixes digital loads with remaining cost and never substitutes a customer bonus for purchase cost', function () {
    [$admin, $cashier] = c367Fixture();
    c367Funding($admin, 'CREDIT', 'INITIAL', '0');
    c367Funding($admin, 'CREDIT', 'TOP_UP', '5250', '5000');
    c367Recharge($cashier, 'CREDIT', '250');
    $account = EntelDigitalAccount::sole();
    $before = $account->cost_value_cents;
    c367Funding($admin, 'CREDIT', 'TOP_UP', '5500', '5000');
    expect($account->fresh()->balance_cents)->toBe(1050000)->and($account->fresh()->cost_value_cents)->toBe($before + 500000);
    $sale = c367Recharge($cashier, 'CREDIT');
    expect($sale->cost_cents)->toBe(app(CardAverageCostCalculator::class)->consume(1050000, $before + 500000, 10000)['consumed']);
});

it('C367 digital initial unknown cost never blocks sales and valuation leaves earlier receipts pending', function () {
    [$admin, $cashier] = c367Fixture();
    c367Funding($admin, 'PACKAGES', 'INITIAL', '300');
    $old = c367Recharge($cashier, 'PACKAGES');
    $account = EntelDigitalAccount::sole();
    $data = c367Value($account, '180');
    $cash = CardStore::sole()->cash_balance_cents;
    $a = app(ValueRemainingCardCostAction::class);
    expect($a->execute($admin, $data))->toBe($a->execute($admin, $data));
    expect($account->fresh()->balance_cents)->toBe(20000)->and(CardStore::sole()->cash_balance_cents)->toBe($cash);
    expect(c367Recharge($cashier, 'PACKAGES')->cost_cents)->toBe(9000)->and($old->fresh()->cost_cents)->toBeNull();
    expect(EntelDigitalMovement::where('operation', 'COST_SET')->count())->toBe(1);
});

it('C367 rejects stale or conflicting valuation retries and does not rewrite known costs', function () {
    [$admin, $cashier, $p] = c367Fixture(null);
    $data = c367Value($p->stock()->sole(), '188');
    c367Sale($cashier, $p);
    $a = app(ValueRemainingCardCostAction::class);
    expect(fn () => $a->execute($admin, $data))->toThrow(ValidationException::class);
    $data = c367Value($p->stock()->sole(), '178.60');
    $a->execute($admin, $data);
    expect(fn () => $a->execute($admin, array_replace($data, ['total_cost' => '1'])))->toThrow(ValidationException::class);
    expect(fn () => $a->execute($admin, c367Value($p->stock()->sole(), '1')))->toThrow(ValidationException::class);
    expect($p->stock()->sole()->cost_value_cents)->toBe(17860);
});

it('C367 denies cashier and revoked administrator access to valuation and private cost queries', function () {
    [$admin, $cashier, $p] = c367Fixture(null);
    $data = c367Value($p->stock()->sole(), '188');
    expect(fn () => app(ValueRemainingCardCostAction::class)->execute($cashier, $data))->toThrow(AuthorizationException::class);
    expect(fn () => app(CardCostsQuery::class)->stocks($cashier))->toThrow(AuthorizationException::class);
    expect(fn () => app(CardCostsQuery::class)->margins($cashier))->toThrow(AuthorizationException::class);
    $admin->forceFill(['is_active' => false])->save();
    expect(fn () => app(ValueRemainingCardCostAction::class)->execute($admin, $data))->toThrow(AuthorizationException::class);
    expect($p->stock()->sole()->cost_value_cents)->toBeNull();
});

it('C367 rolls cost changes back with an interrupted sale and retries exactly once', function () {
    [$admin, $cashier, $p] = c367Fixture();
    $key = (string) Str::uuid();
    DB::unprepared("CREATE TRIGGER c367_interrupt BEFORE INSERT ON card_inventory_commands WHEN NEW.action = 'SALE' BEGIN SELECT RAISE(ABORT, 'interrupted'); END");
    expect(fn () => c367Sale($cashier, $p, 1, '10', $key))->toThrow(QueryException::class);
    expect($p->stock()->sole()->quantity)->toBe(20)->and($p->stock()->sole()->cost_value_cents)->toBe(18800)->and(CardSale::count())->toBe(0);
    DB::unprepared('DROP TRIGGER c367_interrupt');
    c367Sale($cashier, $p, 1, '10', $key);
    c367Sale($cashier, $p, 1, '10', $key);
    expect($p->stock()->sole()->cost_value_cents)->toBe(17860)->and(CardSale::count())->toBe(1);
});

it('C367 conserves exact cents through final depletion and avoids integer multiplication overflow', function () {
    $calc = app(CardAverageCostCalculator::class);
    $first = $calc->consume(3, 100, 1);
    $second = $calc->consume(2, $first['remaining'], 1);
    $last = $calc->consume(1, $second['remaining'], 1);
    expect([$first['consumed'], $second['consumed'], $last['consumed']])->toBe([33, 34, 33]);
    expect($calc->consume(100000000000, 100000000000000000, 50000000000))->toBe(['consumed' => 50000000000000000, 'remaining' => 50000000000000000]);
    expect($calc->afterCount(20, 18800, 18))->toBe(16920)->and($calc->afterCount(20, 18800, 21))->toBeNull();
});

it('C367 records nominal shortages while depleting valuation and taints incorporated surplus without creating profit', function () {
    [$admin, $cashier, $p] = c367Fixture();
    $form = app(CardStoreSessionQuery::class)->form($cashier, 'OPEN');
    $form['stocks'][0]['quantity'] = 18;
    $form['reason'] = 'Conteo real de prueba';
    app(RecordCardStoreCountAction::class)->open($cashier, $form);
    expect($p->stock()->sole()->cost_value_cents)->toBe(16920)->and(EconomicIncident::sum('amount_cents'))->toBe(2000);
    $close = app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE');
    $close['stocks'][0]['quantity'] = 19;
    $close['reason'] = 'Sobrante fÃƒÂ­sico';
    app(RecordCardStoreCountAction::class)->close($cashier, $close);
    $reserve = CardStoreReserve::whereNotNull('card_product_id')->sole();
    expect($p->stock()->sole()->cost_value_cents)->toBe(16920);
    app(ResolveCardStoreReserveAction::class)->execute($admin, $reserve->id, ['method' => 'INCORPORAR', 'amount' => 1, 'reason' => 'IncorporaciÃƒÂ³n autorizada', 'request_key' => (string) Str::uuid()]);
    expect($p->stock()->sole()->quantity)->toBe(19)->and($p->stock()->sole()->cost_value_cents)->toBeNull()->and(CardSaleLine::count())->toBe(0);
});

it('C367 renders administrative costs and completes an unknown digital cost through its modal', function () {
    [$admin, $cashier] = c367Fixture(null);
    c367Funding($admin, 'CREDIT', 'INITIAL', '300');
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    test()->actingAs($admin);
    Livewire::test(CardCosts::class)->assertSee('margen bruto conocido')->assertSee('Pendiente de costo')
        ->callAction('value_CREDIT', data: c367Value(EntelDigitalAccount::sole(), '270'))->assertHasNoActionErrors();
    expect(EntelDigitalAccount::sole()->cost_value_cents)->toBe(27000);
    test()->actingAs($cashier);
    test()->get(CardCosts::getUrl())->assertForbidden();
});

it('C367 rolls digital cost and cash back on interruption and forbids changed replay payloads', function () {
    [$admin, $cashier] = c367Fixture();
    c367Funding($admin, 'CREDIT', 'INITIAL', '0');
    c367Funding($admin, 'CREDIT', 'TOP_UP', '5250', '5000');
    $key = (string) Str::uuid();
    $cash = CardStore::sole()->cash_balance_cents;
    DB::unprepared("CREATE TRIGGER c367_digital_interrupt BEFORE INSERT ON card_inventory_commands WHEN NEW.action = 'ENTEL_RECHARGE' BEGIN SELECT RAISE(ABORT, 'interrupted'); END");
    expect(fn () => c367Recharge($cashier, 'CREDIT', '100', $key))->toThrow(QueryException::class);
    expect(EntelDigitalAccount::sole()->cost_value_cents)->toBe(500000)->and(EntelDigitalAccount::sole()->balance_cents)->toBe(525000)->and(CardStore::sole()->cash_balance_cents)->toBe($cash);
    DB::unprepared('DROP TRIGGER c367_digital_interrupt');
    c367Recharge($cashier, 'CREDIT', '100', $key);
    expect(fn () => c367Recharge($cashier, 'CREDIT', '200', $key))->toThrow(ValidationException::class);
    expect(EntelRecharge::count())->toBe(1)->and(EntelDigitalAccount::sole()->cost_value_cents)->toBe(490476);
});

it('C367 does not restore value for an expired physical surplus of unknown acquisition cost', function () {
    [$admin, $cashier, $p] = c367Fixture();
    test()->freezeTime();
    $open = app(CardStoreSessionQuery::class)->form($cashier, 'OPEN');
    app(RecordCardStoreCountAction::class)->open($cashier, $open);
    $close = app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE');
    $close['stocks'][0]['quantity'] = 21;
    $close['reason'] = 'Hallazgo de cierre';
    app(RecordCardStoreCountAction::class)->close($cashier, $close);
    test()->travel(6)->days();
    $new = app(CardStoreSessionQuery::class)->form($cashier, 'OPEN');
    app(RecordCardStoreCountAction::class)->open($cashier, $new);
    expect($p->stock()->sole()->quantity)->toBe(21)->and($p->stock()->sole()->cost_value_cents)->toBeNull();
    expect(CardSaleLine::count())->toBe(0);
});

it('C367 completes a digital initial cost before a physical store has been initialized', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    test()->seed([UsuariosPermissionsSeeder::class, EntelDigitalPermissionsSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    c367Funding($admin, 'CREDIT', 'INITIAL', '300');
    app(ValueRemainingCardCostAction::class)->execute($admin, c367Value(EntelDigitalAccount::sole(), '270'));
    expect(EntelDigitalAccount::sole()->cost_value_cents)->toBe(27000)->and(CardStore::count())->toBe(0);
});
