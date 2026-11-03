<?php

use App\Domain\Incidencias\Actions\ReportEconomicIncidentAction;
use App\Domain\Incidencias\Data\IncidentOrigin;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Queries\OwnEconomicHistoryQuery;
use App\Domain\Incidencias\Queries\OwnIncidentDetailQuery;
use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordCardStoreCountAction;
use App\Domain\Tarjetas\Actions\ResolveCardStoreReserveAction;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Domain\Tarjetas\Models\CardStoreReserve;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Models\User;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function c178Fixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $a = User::factory()->create();
    $a->assignRole('cajera');
    $b = User::factory()->create();
    $b->assignRole('cajera');
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 100000, 'initialized_at' => now(), 'initialized_by' => $admin->id]);
    $p = CardProduct::create(['company' => 'TIGO', 'face_value_cents' => 1000, 'purchase_price_cents' => 940, 'normal_price_cents' => 1000, 'wholesale_price_cents' => 950, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
    CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 100]);

    return [$admin, $a, $b, $p];
}
function c178Count(User $u, string $kind): array
{
    return app(CardStoreSessionQuery::class)->form($u, $kind);
}
it('C178 closes from actual counts and opening does not duplicate the previous shortage', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    $act = app(RecordCardStoreCountAction::class);
    $open = c178Count($a, 'OPEN');
    $first = $act->open($a, $open);
    expect($act->open($a, $open)->id)->toBe($first->id);
    $close = c178Count($a, 'CLOSE');
    $close['stocks'][0]['quantity'] = 97;
    $close['cash'] = '995';
    $closed = $act->close($a, $close);
    $act->close($a, $close);
    expect(CardStock::sole()->quantity)->toBe(97)->and(CardStore::sole()->cash_balance_cents)->toBe(99500)->and(EconomicIncident::sum('amount_cents'))->toBe(3500)->and(EconomicIncident::count())->toBe(2);
    $received = $act->open($b, c178Count($b, 'OPEN'));
    expect(EconomicIncident::count())->toBe(2)->and($received->shortage_cents)->toBe(0);
    $act->close($b, c178Count($b, 'CLOSE'));
    $next = c178Count($a, 'OPEN');
    $next['stocks'][0]['quantity'] = 96;
    $next['cash'] = '994';
    $act->open($a, $next);
    expect(EconomicIncident::where('user_id', $b->id)->sum('pending_cents'))->toBe(1100)->and(CardStock::sole()->quantity)->toBe(96)->and(CardStore::sole()->cash_balance_cents)->toBe(99400);
    expect(EconomicIncident::where('status', 'CONFIRMADA')->count())->toBe(4);
});
it('C178 rejects stale counts duplicate active sessions and operations by another cashier', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    $act = app(RecordCardStoreCountAction::class);
    $act->open($a, c178Count($a, 'OPEN'));
    expect(fn () => $act->open($b, c178Count($b, 'OPEN')))->toThrow(ValidationException::class);
    expect(fn () => $act->close($b, c178Count($b, 'CLOSE')))->toThrow(ValidationException::class);
    $stale = c178Count($a, 'CLOSE');
    $sale = ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $p->id, 'quantity' => 3, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()];
    expect(fn () => app(RecordCardSaleAction::class)->execute($b, $sale))->toThrow(ValidationException::class);
    app(RecordCardSaleAction::class)->execute($a, $sale);
    expect(fn () => $act->close($a, $stale))->toThrow(ValidationException::class);
    $act->close($a, c178Count($a, 'CLOSE'));
    expect(EconomicIncident::count())->toBe(0);
    expect(fn () => app(RecordCardSaleAction::class)->execute($a, array_replace($sale, ['request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
});
it('C178 rolls back count stock cash session and debts if incidence auditing fails', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    $act = app(RecordCardStoreCountAction::class);
    $act->open($a, c178Count($a, 'OPEN'));
    $close = c178Count($a, 'CLOSE');
    $close['cash'] = '990';
    $revision = CardStore::sole()->revision;
    DB::statement("CREATE TRIGGER c178_fail BEFORE INSERT ON economic_history_events BEGIN SELECT RAISE(ABORT,'interrupted'); END");
    expect(fn () => $act->close($a, $close))->toThrow(QueryException::class);
    expect(CardStoreSession::where('active_slot', 1)->count())->toBe(1)->and(CardStoreCount::count())->toBe(1)->and(EconomicIncident::count())->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(100000)->and(CardStore::sole()->revision)->toBe($revision);
});
it('C178 reserves surpluses instead of compensating shortages and preserves them on reception', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    $act = app(RecordCardStoreCountAction::class);
    $act->open($a, c178Count($a, 'OPEN'));
    $close = c178Count($a, 'CLOSE');
    $close['cash'] = '1010';
    $close['stocks'][0]['quantity'] = 99;
    $close['reason'] = 'Dinero encontrado';
    $act->close($a, $close);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000)->and(CardStoreReserve::sole()->held_amount)->toBe(1000)->and(EconomicIncident::sole()->amount_cents)->toBe(1000);
    $form = c178Count($b, 'OPEN');
    expect($form['cash'])->toBe('1010');
    $act->open($b, $form);
    expect(EconomicIncident::count())->toBe(1)->and(CardStoreReserve::count())->toBe(1);
    $this->travel(6)->days();
    $act->close($b, c178Count($b, 'CLOSE'));
    expect(CardStore::sole()->cash_balance_cents)->toBe(101000)->and(CardStoreReserve::sole()->held_amount)->toBe(0)->and(EconomicIncident::count())->toBe(1);
});

it('C178 rejects incomplete duplicated negative or stale catalog counts without any session', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    $act = app(RecordCardStoreCountAction::class);
    $base = c178Count($a, 'OPEN');
    foreach ([[], [$base['stocks'][0], $base['stocks'][0]], [array_replace($base['stocks'][0], ['quantity' => -1])]] as $stocks) {
        expect(fn () => $act->open($a, array_replace($base, ['stocks' => $stocks, 'request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    }
    expect(CardStoreSession::count())->toBe(0)->and(CardStoreCount::count())->toBe(0)->and(EconomicIncident::count())->toBe(0);
});
it('C178 counts empty fields as zero and values missing cards by face amount not changed selling price', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    $act = app(RecordCardStoreCountAction::class);
    $act->open($a, c178Count($a, 'OPEN'));
    $p->update(['normal_price_cents' => 1, 'wholesale_price_cents' => 1]);
    $close = c178Count($a, 'CLOSE');
    $close['cash'] = '';
    $close['stocks'][0]['quantity'] = '';
    $act->close($a, $close);
    expect(CardStore::sole()->cash_balance_cents)->toBe(0)->and(CardStock::sole()->quantity)->toBe(0)->and(EconomicIncident::sum('amount_cents'))->toBe(200000);
});
it('C178 first count assigns the initial shortage to the administrator who initialized the store', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    $open = c178Count($a, 'OPEN');
    $open['cash'] = '990';
    $count = app(RecordCardStoreCountAction::class)->open($a, $open);
    expect($count->responsible_user_id)->toBe($admin->id)->and($count->shortage_cents)->toBe(1000)->and(EconomicIncident::sole()->user_id)->toBe($admin->id)->and(CardStore::sole()->cash_balance_cents)->toBe(99000);
});
it('C178 historical shortages appear only in their owner profile with opening or closing origin', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    $act = app(RecordCardStoreCountAction::class);
    $act->open($a, c178Count($a, 'OPEN'));
    $close = c178Count($a, 'CLOSE');
    $close['cash'] = '999';
    $act->close($a, $close);
    $incident = EconomicIncident::sole();
    expect(app(OwnEconomicHistoryQuery::class)->execute($a)->count())->toBe(1)->and(app(OwnEconomicHistoryQuery::class)->execute($b)->count())->toBe(0);
    $detail = app(OwnIncidentDetailQuery::class)->execute($a, $incident->id);
    expect($detail['origin']['label'])->toBe('Cierre de turno')->and($detail['origin']['detected_by'])->toBe($a->name);
    expect(fn () => app(OwnIncidentDetailQuery::class)->execute($b, $incident->id))->toThrow(AuthorizationException::class);
    $open = c178Count($b, 'OPEN');
    $open['cash'] = '998';
    $act->open($b, $open);
    $new = EconomicIncident::latest('id')->first();
    expect(IncidentOrigin::label($new))->toBe('Detectado en apertura')->and($new->user_id)->toBe($a->id)->and($new->reported_by)->toBe($b->id);
});

it('C178 keeps card surplus unavailable and does not duplicate a deficit in a held reserve', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    $act = app(RecordCardStoreCountAction::class);
    $act->open($a, c178Count($a, 'OPEN'));
    $close = c178Count($a, 'CLOSE');
    $close['stocks'][0]['quantity'] = 102;
    $close['reason'] = 'Dos tarjetas encontradas';
    $act->close($a, $close);
    expect(CardStock::sole()->quantity)->toBe(100)->and(CardStoreReserve::sole()->held_amount)->toBe(2);
    $open = c178Count($b, 'OPEN');
    $open['stocks'][0]['quantity'] = 1;
    $act->open($b, $open);
    expect(CardStock::sole()->quantity)->toBe(0)->and(CardStoreReserve::sole()->held_amount)->toBe(1)->and(CardStoreReserve::sole()->missing_amount)->toBe(1)->and(EconomicIncident::sum('amount_cents'))->toBe(101000);
    $act->close($b, c178Count($b, 'CLOSE'));
    $act->open($a, c178Count($a, 'OPEN'));
    expect(EconomicIncident::sum('amount_cents'))->toBe(101000);
});
it('C178 first differing count does not invent an initializer if migrated metadata is missing', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    CardStore::sole()->update(['initialized_by' => null]);
    $open = c178Count($a, 'OPEN');
    $open['cash'] = '990';
    expect(fn () => app(RecordCardStoreCountAction::class)->open($a, $open))->toThrow(ValidationException::class);
    expect(CardStoreCount::count())->toBe(0)->and(EconomicIncident::count())->toBe(0)->and(CardStore::sole()->cash_balance_cents)->toBe(100000);
});
it('C178 inactive cuts with stock are counted and sale movements link to the actual session', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    $p->update(['is_active' => false]);
    $open = c178Count($a, 'OPEN');
    expect($open['stocks'])->toHaveCount(1);
    $act = app(RecordCardStoreCountAction::class);
    $act->open($a, $open);
    $p->update(['is_active' => true]);
    $sale = app(RecordCardSaleAction::class)->execute($a, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()]);
    expect(CardInventoryMovement::where('kind', 'SALE_NORMAL')->sole()->card_store_session_id)->toBe(CardStoreSession::sole()->id);
    $act->close($a, c178Count($a, 'CLOSE'));
    expect(EconomicIncident::count())->toBe(0);
});
it('C178 does not accept a forged manual reference for an automatic count incident', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    expect(fn () => app(ReportEconomicIncidentAction::class)->execute($a, $a, ['source_module' => 'tarjetas', 'source_reference' => 'card-close:1', 'component' => 'efectivo', 'amount_cents' => 100, 'occurred_at' => now()]))->toThrow(ValidationException::class);
    expect(EconomicIncident::count())->toBe(0);
});

it('C178 keeps opening surpluses indefinitely and only administration resolves them without duplicates', function () {
    [$admin,$a,$b,$p] = c178Fixture();
    $act = app(RecordCardStoreCountAction::class);
    $open = c178Count($a, 'OPEN');
    $open['cash'] = '1010';
    $open['stocks'][0]['quantity'] = 102;
    $open['reason'] = 'Hallazgo inicial';
    $act->open($a, $open);
    expect(CardStoreReserve::whereNotNull('expires_at')->count())->toBe(0);
    $this->travel(6)->days();
    $act->close($a, c178Count($a, 'CLOSE'));
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000)->and(CardStock::sole()->quantity)->toBe(100)->and(CardStoreReserve::sum('held_amount'))->toBe(1002);
    $cash = CardStoreReserve::whereNull('card_product_id')->sole();
    $cards = CardStoreReserve::whereNotNull('card_product_id')->sole();
    $resolve = app(ResolveCardStoreReserveAction::class);
    $data = ['method' => 'INCORPORAR', 'amount' => '5', 'reason' => 'Verificado', 'request_key' => (string) Str::uuid()];
    expect(fn () => $resolve->execute($a, $cash->id, $data))->toThrow(AuthorizationException::class);
    $movement = $resolve->execute($admin, $cash->id, $data);
    expect($resolve->execute($admin, $cash->id, $data)->id)->toBe($movement->id);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100500)->and($cash->fresh()->held_amount)->toBe(500);
    expect(fn () => $resolve->execute($admin, $cash->id, array_replace($data, ['amount' => '6'])))->toThrow(ValidationException::class);
    $resolve->execute($admin, $cash->id, array_replace($data, ['method' => 'DEVOLVER', 'request_key' => (string) Str::uuid()]));
    $resolve->execute($admin, $cards->id, array_replace($data, ['amount' => 1, 'request_key' => (string) Str::uuid()]));
    expect(CardStore::sole()->cash_balance_cents)->toBe(100500)->and(CardStock::sole()->quantity)->toBe(101)->and($cash->fresh()->released_at)->not->toBeNull()->and(EconomicIncident::count())->toBe(0);
    expect(fn () => $resolve->execute($admin, $cards->id, array_replace($data, ['amount' => 2, 'request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    $resolve->execute($admin, $cards->id, array_replace($data, ['method' => 'DEVOLVER', 'amount' => 1, 'request_key' => (string) Str::uuid()]));
    expect(CardStock::sole()->quantity)->toBe(101)->and($cards->fresh()->held_amount)->toBe(0);
});
