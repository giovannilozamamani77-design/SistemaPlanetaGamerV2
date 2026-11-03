<?php

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Queries\OwnEconomicHistoryQuery;
use App\Domain\Tarjetas\Actions\RecordCardCashDepositAction;
use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordCardStockBatchAction;
use App\Domain\Tarjetas\Actions\RecordCardStoreCountAction;
use App\Domain\Tarjetas\Actions\ResolveCardStoreReserveAction;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreReserve;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Models\User;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Tarjetas\CardDepositsPermissionsSeeder;
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

function c180Fixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, CardDepositsPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $a = User::factory()->create();
    $a->assignRole('cajera');
    $b = User::factory()->create();
    $b->assignRole('cajera');
    $store = CardStore::create(['code' => 'TIENDA', 'cash_balance_cents' => 100000, 'initialized_at' => now(), 'initialized_by' => $admin->id]);
    $products = [];
    foreach (['TIGO' => 100, 'VIVA' => 20] as $company => $quantity) {
        $p = CardProduct::create(['company' => $company, 'face_value_cents' => 1000, 'purchase_price_cents' => 940, 'normal_price_cents' => 1000, 'wholesale_price_cents' => 950, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => $quantity]);
        $products[$company] = $p;
    }

    return [$admin, $a, $b, $products['TIGO'], $products['VIVA']];
}
function c180Form(User $user, string $kind, array $changes = []): array
{
    $form = app(CardStoreSessionQuery::class)->form($user, $kind);
    foreach ($form['stocks'] as &$line) {
        if (array_key_exists($line['product_id'], $changes)) {
            $line['quantity'] = $changes[$line['product_id']];
        }
    }unset($line);

    return $form;
}
function c180Resolution(string $method, string|int $amount): array
{
    return ['method' => $method, 'amount' => $amount, 'reason' => 'Resolución de prueba documentada', 'request_key' => (string) Str::uuid()];
}
it('C180 reserves opening and closing surpluses through sales deposit handoff and expiry without netting debts', function () {
    [$admin,$a,$b,$t,$v] = c180Fixture();
    $counts = app(RecordCardStoreCountAction::class);
    $resolve = app(ResolveCardStoreReserveAction::class);
    $open = c180Form($a, 'OPEN', [$t->id => 102, $v->id => 19]);
    $open['cash'] = '1010';
    $open['reason'] = 'Hallazgo de recepción';
    $first = $counts->open($a, $open);
    expect($counts->open($a, $open)->id)->toBe($first->id);
    $cashReserve = CardStoreReserve::whereNull('card_product_id')->sole();
    $cardReserve = CardStoreReserve::where('card_product_id', $t->id)->sole();
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000);
    expect($t->stock()->first()->quantity)->toBe(100);
    expect($v->stock()->first()->quantity)->toBe(19);
    expect($cashReserve->held_amount)->toBe(1000);
    expect($cardReserve->held_amount)->toBe(2);
    expect(CardStoreReserve::whereNotNull('expires_at')->count())->toBe(0);
    expect(app(OwnEconomicHistoryQuery::class)->execute($admin)->sum('pending_cents'))->toBe(1000);
    $this->travel(6)->days();
    $sell = ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $t->id, 'quantity' => 100, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()];
    app(RecordCardSaleAction::class)->execute($a, $sell);
    expect(CardStore::sole()->cash_balance_cents)->toBe(200000);
    expect($t->stock()->first()->quantity)->toBe(0);
    expect($cashReserve->fresh()->held_amount)->toBe(1000);
    expect(fn () => app(RecordCardSaleAction::class)->execute($a, array_replace($sell, ['lines' => [['product_id' => $t->id, 'quantity' => 1, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    $account = CardDepositAccount::create(['name' => 'Destino prueba', 'bank' => 'Prueba', 'number' => 'C180', 'holder' => 'Administración prueba', 'qr_path' => 'test-only.png', 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
    $deposit = ['account_id' => $account->id, 'account_revision' => 1, 'amount' => '2005', 'request_key' => (string) Str::uuid()];
    expect(fn () => app(RecordCardCashDepositAction::class)->execute($a, $deposit))->toThrow(ValidationException::class);
    app(RecordCardCashDepositAction::class)->execute($a, array_replace($deposit, ['amount' => '1000', 'request_key' => (string) Str::uuid()]));
    expect(fn () => app(RecordCardStockBatchAction::class)->purchase($a, ['company' => 'TIGO', 'lines' => [['product_id' => $t->id, 'quantity' => 107, 'unit_price' => '9.40']], 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    // 1005.80 would fit physical cash1010 but must fail against available1000.
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000);
    expect(fn () => $resolve->execute($a, $cashReserve->id, c180Resolution('INCORPORAR', '5')))->toThrow(AuthorizationException::class);
    $returnCash = c180Resolution('DEVOLVER', '5');
    $m = $resolve->execute($admin, $cashReserve->id, $returnCash);
    expect($resolve->execute($admin, $cashReserve->id, $returnCash)->id)->toBe($m->id);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000);
    expect($cashReserve->fresh()->held_amount)->toBe(500);
    $incCash = c180Resolution('INCORPORAR', '5');
    $m = $resolve->execute($admin, $cashReserve->id, $incCash);
    expect($resolve->execute($admin, $cashReserve->id, $incCash)->id)->toBe($m->id);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100500);
    $returnCard = c180Resolution('DEVOLVER', 1);
    $m = $resolve->execute($admin, $cardReserve->id, $returnCard);
    expect($resolve->execute($admin, $cardReserve->id, $returnCard)->id)->toBe($m->id);
    expect($t->stock()->first()->quantity)->toBe(0);
    $incCard = c180Resolution('INCORPORAR', 1);
    $m = $resolve->execute($admin, $cardReserve->id, $incCard);
    expect($resolve->execute($admin, $cardReserve->id, $incCard)->id)->toBe($m->id);
    expect($t->stock()->first()->quantity)->toBe(1);
    expect(EconomicIncident::sum('pending_cents'))->toBe(1000);
    $close = c180Form($a, 'CLOSE', [$t->id => 0, $v->id => 21]);
    $close['cash'] = '1025';
    $close['reason'] = 'Sobrantes de cierre separados';
    $closed = $counts->close($a, $close);
    $counts->close($a, $close);
    $closing = CardStoreReserve::where('card_store_count_id', $closed->id)->get();
    expect($closing)->toHaveCount(2);
    expect($closing->whereNull('expires_at'))->toHaveCount(0);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100500);
    expect($v->stock()->first()->quantity)->toBe(19);
    expect(app(OwnEconomicHistoryQuery::class)->execute($a)->sum('pending_cents'))->toBe(1000);
    $reserveSnapshot = $closing->map(fn ($r) => [$r->id, $r->held_amount, $r->expires_at->toISOString()])->all();
    $received = $counts->open($b, c180Form($b, 'OPEN'));
    expect($received->shortage_cents)->toBe(0);
    expect(CardStoreReserve::where('card_store_count_id', $closed->id)->get()->map(fn ($r) => [$r->id, $r->held_amount, $r->expires_at->toISOString()])->all())->toBe($reserveSnapshot);
    $this->travel(4)->days();
    $counts->close($b, c180Form($b, 'CLOSE'));
    $counts->open($a, c180Form($a, 'OPEN'));
    expect(CardStore::sole()->cash_balance_cents)->toBe(100500);
    expect($v->stock()->first()->quantity)->toBe(19);
    $this->travel(2)->days();
    $endForm = c180Form($a, 'CLOSE');
    $end = $counts->close($a, $endForm);
    $counts->close($a, $endForm);
    expect($end->shortage_cents)->toBe(0);
    expect(CardStore::sole()->cash_balance_cents)->toBe(102500);
    expect($v->stock()->first()->quantity)->toBe(21);
    expect(CardInventoryMovement::where('kind', 'SURPLUS_RELEASE_CASH')->sum('cash_delta_cents'))->toBe(2000);
    expect(CardInventoryMovement::where('kind', 'SURPLUS_RELEASE_STOCK')->sum('quantity'))->toBe(2);
    expect(EconomicIncident::count())->toBe(2);
    expect(EconomicIncident::sum('pending_cents'))->toBe(2000);
    expect(app(OwnEconomicHistoryQuery::class)->execute($b)->count())->toBe(0);
});

it('C180 administrative card surplus resolution keeps cash and card shortages intact and invalidates stale counts', function () {
    [$admin,$a,$b,$t,$v] = c180Fixture();
    $counts = app(RecordCardStoreCountAction::class);
    $counts->open($a, c180Form($a, 'OPEN'));
    $form = c180Form($a, 'CLOSE', [$t->id => 103, $v->id => 19]);
    $form['cash'] = '996';
    $form['reason'] = 'Tres tarjetas encontradas';
    $closed = $counts->close($a, $form);
    expect(EconomicIncident::sum('pending_cents'))->toBe(1400);
    expect(CardStore::sole()->cash_balance_cents)->toBe(99600);
    expect($t->stock()->first()->quantity)->toBe(100);
    $counts->open($b, c180Form($b, 'OPEN'));
    $stale = c180Form($b, 'CLOSE');
    $r = CardStoreReserve::sole();
    $resolve = app(ResolveCardStoreReserveAction::class);
    $inc = c180Resolution('INCORPORAR', 1);
    $m = $resolve->execute($admin, $r->id, $inc);
    expect($resolve->execute($admin, $r->id, $inc)->id)->toBe($m->id);
    expect($t->stock()->first()->quantity)->toBe(101);
    expect($r->fresh()->held_amount)->toBe(2);
    expect(fn () => $counts->close($b, $stale))->toThrow(ValidationException::class);
    $return = c180Resolution('DEVOLVER', 2);
    $m = $resolve->execute($admin, $r->id, $return);
    expect($resolve->execute($admin, $r->id, $return)->id)->toBe($m->id);
    expect($r->fresh()->released_at)->not->toBeNull();
    expect($t->stock()->first()->quantity)->toBe(101);
    expect(CardStore::sole()->cash_balance_cents)->toBe(99600);
    expect($counts->close($b, c180Form($b, 'CLOSE'))->shortage_cents)->toBe(0);
    expect(app(OwnEconomicHistoryQuery::class)->execute($a)->sum('pending_cents'))->toBe(1400);
    expect(app(OwnEconomicHistoryQuery::class)->execute($b)->count())->toBe(0);
    expect(CardInventoryMovement::where('kind', 'SURPLUS_RESOLVE')->count())->toBe(2);
});

it('C180 rolls back surplus resolution if its audit fails and permits one clean retry', function () {
    [$admin,$a,$b,$t,$v] = c180Fixture();
    $form = c180Form($a, 'OPEN');
    $form['cash'] = '1010';
    $form['reason'] = 'Hallazgo';
    app(RecordCardStoreCountAction::class)->open($a, $form);
    $r = CardStoreReserve::sole();
    $movements = CardInventoryMovement::count();
    $revision = CardStore::sole()->revision;
    $data = c180Resolution('INCORPORAR', '10');
    DB::statement("CREATE TRIGGER c180_interrupt BEFORE INSERT ON card_inventory_commands WHEN NEW.action = 'SURPLUS_RESOLVE' BEGIN SELECT RAISE(ABORT,'test interruption'); END");
    expect(fn () => app(ResolveCardStoreReserveAction::class)->execute($admin, $r->id, $data))->toThrow(QueryException::class);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000);
    expect(CardStore::sole()->revision)->toBe($revision);
    expect($r->fresh()->held_amount)->toBe(1000);
    expect($r->fresh()->released_at)->toBeNull();
    expect(CardInventoryMovement::count())->toBe($movements);
    DB::statement('DROP TRIGGER c180_interrupt');
    $m = app(ResolveCardStoreReserveAction::class)->execute($admin, $r->id, $data);
    expect(app(ResolveCardStoreReserveAction::class)->execute($admin, $r->id, $data)->id)->toBe($m->id);
    expect(CardStore::sole()->cash_balance_cents)->toBe(101000);
    expect($r->fresh()->held_amount)->toBe(0);
    expect(CardInventoryMovement::count())->toBe($movements + 1);
    expect(EconomicIncident::count())->toBe(0);
});
