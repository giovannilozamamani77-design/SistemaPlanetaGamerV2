<?php

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Queries\OwnEconomicHistoryQuery;
use App\Domain\Incidencias\Queries\OwnIncidentDetailQuery;
use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\RecordCardCashDepositAction;
use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordCardStockBatchAction;
use App\Domain\Tarjetas\Actions\RecordCardStoreCountAction;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Models\User;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Tarjetas\CardDepositsPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('C179 reconciles sales purchases deposits and two custodians without duplicating profile debts', function (bool $short, bool $extra) {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, CardDepositsPermissionsSeeder::class, IncidenciasPermissionsSeeder::class]);
    $admin = User::factory()->create();
    $admin->assignRole('administrador');
    $a = User::factory()->create();
    $a->assignRole('cajera');
    $b = User::factory()->create();
    $b->assignRole('cajera');
    $store = CardStore::create(['code' => 'TIENDA']);
    $products = [];
    foreach (['TIGO' => 940, 'VIVA' => 930] as $company => $purchase) {
        $p = CardProduct::create(['company' => $company, 'face_value_cents' => 1000, 'purchase_price_cents' => $purchase, 'normal_price_cents' => 1000, 'wholesale_price_cents' => 950, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 0]);
        $products[$company] = $p;
    }
    $t = $products['TIGO'];
    $v = $products['VIVA'];
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '1000', 'stocks' => [['product_id' => $t->id, 'quantity' => 100], ['product_id' => $v->id, 'quantity' => 20]], 'request_key' => (string) Str::uuid()]);
    $account = CardDepositAccount::create(['name' => 'Destino de prueba', 'bank' => 'Banco prueba', 'number' => 'TEST-C179', 'holder' => 'Administración prueba', 'qr_path' => 'test-only.png', 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
    $counts = app(RecordCardStoreCountAction::class);
    $query = app(CardStoreSessionQuery::class);
    $sale = app(RecordCardSaleAction::class);
    $buy = app(RecordCardStockBatchAction::class);
    $deposit = app(RecordCardCashDepositAction::class);
    $open = $query->form($a, 'OPEN');
    $first = $counts->open($a, $open);
    expect($counts->open($a, $open)->id)->toBe($first->id);
    $sellA = ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $t->id, 'quantity' => 3, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()];
    $saleA = $sale->execute($a, $sellA);
    expect($sale->execute($a, $sellA)->id)->toBe($saleA->id);
    foreach ([['TIGO', $t, 10, '9.40'], ['VIVA', $v, 2, '9.30']] as [$company,$product,$quantity,$price]) {
        $data = ['company' => $company, 'lines' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price]], 'request_key' => (string) Str::uuid()];
        $result = $buy->purchase($a, $data);
        expect($buy->purchase($a, $data))->toBe($result);
    }
    $depA = ['account_id' => $account->id, 'account_revision' => 1, 'amount' => '300', 'operation_reference' => 'C179-A', 'request_key' => (string) Str::uuid()];
    $movementA = $deposit->execute($a, $depA);
    expect($deposit->execute($a, $depA)->id)->toBe($movementA->id);
    expect(CardStore::sole()->cash_balance_cents)->toBe(61740);
    expect($t->stock()->first()->quantity)->toBe(107);
    expect($v->stock()->first()->quantity)->toBe(22);
    // Rejected operations must not create false shortages or move physical values.
    expect(fn () => $buy->purchase($a, ['company' => 'TIGO', 'lines' => [['product_id' => $t->id, 'quantity' => 1000, 'unit_price' => '9.40']], 'request_key' => (string) Str::uuid()]))->toThrow(ValidationException::class);
    expect(fn () => $deposit->execute($a, array_replace($depA, ['amount' => '10000', 'request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    expect(fn () => $sale->execute($b, array_replace($sellA, ['request_key' => (string) Str::uuid()])))->toThrow(ValidationException::class);
    $closeA = $query->form($a, 'CLOSE');
    if ($short) {
        $closeA['cash'] = '612.40';
        foreach ($closeA['stocks'] as &$line) {
            if ($line['product_id'] === $t->id) {
                $line['quantity'] = 105;
            }
        }unset($line);
    }
    $closedA = $counts->close($a, $closeA);
    expect($counts->close($a, $closeA)->id)->toBe($closedA->id);
    expect($closedA->shortage_cents)->toBe($short ? 2500 : 0);
    $receive = $query->form($b, 'OPEN');
    if ($extra) {
        $receive['cash'] = '611.40';
        foreach ($receive['stocks'] as &$line) {
            if ($line['product_id'] === $v->id) {
                $line['quantity'] = 21;
            }
        }unset($line);
    }
    $openedB = $counts->open($b, $receive);
    expect($counts->open($b, $receive)->id)->toBe($openedB->id);
    expect($openedB->shortage_cents)->toBe($extra ? 1100 : 0);
    expect($openedB->responsible_user_id)->toBe($a->id);
    // A retry from the old cashier after handoff must return history, never close B.
    expect($counts->close($a, $closeA)->id)->toBe($closedA->id);
    expect(CardStoreSession::where('active_slot', 1)->sole()->user_id)->toBe($b->id);
    $sellB = ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $v->id, 'quantity' => 2, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()];
    $saleB = $sale->execute($b, $sellB);
    expect($sale->execute($b, $sellB)->id)->toBe($saleB->id);
    $buyB = ['company' => 'TIGO', 'lines' => [['product_id' => $t->id, 'quantity' => 5, 'unit_price' => '9.40']], 'request_key' => (string) Str::uuid()];
    $purchasedB = $buy->purchase($b, $buyB);
    expect($buy->purchase($b, $buyB))->toBe($purchasedB);
    $depB = array_replace($depA, ['amount' => '100', 'operation_reference' => 'C179-B', 'request_key' => (string) Str::uuid()]);
    $depositedB = $deposit->execute($b, $depB);
    expect($deposit->execute($b, $depB)->id)->toBe($depositedB->id);
    $closeB = $query->form($b, 'CLOSE');
    if ($short) {
        $closeB['cash'] = $extra ? '482.40' : '483.40';
        foreach ($closeB['stocks'] as &$line) {
            if ($line['product_id'] === $t->id) {
                $line['quantity'] = 109;
            }
        }unset($line);
    }
    $closedB = $counts->close($b, $closeB);
    expect($counts->close($b, $closeB)->id)->toBe($closedB->id);
    expect($closedB->shortage_cents)->toBe($short ? 1200 : 0);
    $counts->open($a, $query->form($a, 'OPEN'));
    $own = app(OwnEconomicHistoryQuery::class);
    expect($own->execute($a)->sum('pending_cents'))->toBe($short ? ($extra ? 3600 : 2500) : 0);
    expect($own->execute($b)->sum('pending_cents'))->toBe($short ? 1200 : 0);
    expect($own->execute($admin)->count())->toBe(0);
    expect(EconomicIncident::count())->toBe($short ? ($extra ? 6 : 4) : 0);
    expect(EconomicIncident::where('status', 'CONFIRMADA')->count())->toBe(EconomicIncident::count());
    if ($short) {
        $caseA = $own->execute($a)->first();
        expect(fn () => app(OwnIncidentDetailQuery::class)->execute($b, $caseA->id))->toThrow(AuthorizationException::class);
        $detail = app(OwnIncidentDetailQuery::class)->execute($a, $caseA->id);
        expect($detail['origin']['label'])->toBe('Cierre de turno');
    }
    if ($extra) {
        $cases = EconomicIncident::where('source_reference', 'card-open:'.$openedB->id)->get();
        expect($cases)->toHaveCount(2);
        expect($cases->pluck('user_id')->unique()->all())->toBe([$a->id]);
        expect($cases->pluck('reported_by')->unique()->all())->toBe([$b->id]);
    }
    expect(CardStore::sole()->cash_balance_cents)->toBe($short ? ($extra ? 48240 : 48340) : 49040);
    expect($t->stock()->first()->quantity)->toBe($short ? 109 : 112);
    expect($v->stock()->first()->quantity)->toBe($extra ? 19 : 20);
    expect(CardStoreCount::count())->toBe(5);
    expect(CardStoreSession::where('active_slot', 1)->count())->toBe(1);
    expect(CardInventoryMovement::where('kind', 'ADMIN_CASH_DEPOSIT')->sum('cash_delta_cents'))->toBe(-40000);
    expect(CardInventoryMovement::where('kind', 'PURCHASE')->sum('cash_delta_cents'))->toBe(-15960);
    expect(CardInventoryMovement::whereIn('kind', ['SALE_NORMAL', 'PURCHASE', 'ADMIN_CASH_DEPOSIT'])->whereNull('card_store_session_id')->count())->toBe(0);
})->with(['sin diferencias' => [false, false], 'faltantes al cerrar' => [true, false], 'falta adicional al recibir' => [true, true]]);
