<?php

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Notificaciones\Actions\ProcessTelegramDeliveryAction;
use App\Domain\Notificaciones\Actions\RecordCardTelegramNoticeAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\RecordCardCashDepositAction;
use App\Domain\Tarjetas\Actions\RecordCardCashFundingAction;
use App\Domain\Tarjetas\Actions\RecordCardPurchaseAction;
use App\Domain\Tarjetas\Actions\RecordCardSaleAction;
use App\Domain\Tarjetas\Actions\RecordCardStockBatchAction;
use App\Domain\Tarjetas\Actions\RecordCardStoreCountAction;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Domain\Tarjetas\Models\CardInventoryCommand;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Domain\Tarjetas\Models\CardStoreReserve;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Tarjetas\CardCashFundingPermissionsSeeder;
use Database\Seeders\Tarjetas\CardDepositsPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function tgCardFixture(bool $enabled = true): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-29 08:00', 'America/La_Paz'));
    config(['telegram.sending_enabled' => $enabled]);
    Http::preventStrayRequests();
    Queue::fake();
    test()->seed([UsuariosPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, CardDepositsPermissionsSeeder::class, CardCashFundingPermissionsSeeder::class, IncidenciasPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    $admin = User::factory()->create(['name' => 'Admin prueba']);
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['name' => 'Lili prueba']);
    $cashier->assignRole('cajera');
    $next = User::factory()->create(['name' => 'Ana prueba']);
    $next->assignRole('cajera');
    $store = CardStore::create(['code' => 'TIENDA']);
    $products = [];
    $rows = [];
    foreach ([['TIGO', 1000, 940], ['TIGO', 2000, 1880], ['VIVA', 1000, 930], ['VIVA', 4000, 3950], ['ENTEL', 1000, 917], ['ENTEL', 3000, 2688]] as [$company,$face,$price]) {
        $p = CardProduct::create(['company' => $company, 'face_value_cents' => $face, 'purchase_price_cents' => $price, 'normal_price_cents' => $face, 'wholesale_price_cents' => $face, 'wholesale_enabled' => true, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 0]);
        $products[$company][] = $p;
        $rows[] = ['product_id' => $p->id, 'quantity' => 10];
    }
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '1000', 'stocks' => $rows, 'request_key' => (string) Str::uuid()]);
    $account = CardDepositAccount::create(['name' => 'Cuenta de prueba', 'bank' => 'Banco prueba', 'number' => 'NUMERO-PRIVADO', 'holder' => 'TITULAR-PRIVADO', 'qr_path' => 'PRIVADO.png', 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
    $bot = TelegramBotSetting::create(['module' => 'TARJETAS', 'name' => 'Bot ficticio', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', 'test'), 'is_enabled' => $enabled, 'revision' => 1, 'updated_by' => $admin->id]);
    $dest = TelegramDestination::create(['bot_id' => $bot->id, 'responsible_id' => $admin->id, 'name' => 'Admin ficticio', 'chat_id' => '-100123', 'kind' => 'GROUP', 'is_enabled' => $enabled, 'revision' => 1, 'updated_by' => $admin->id]);
    foreach (['TJ_APERTURA', 'TJ_CIERRE', 'TJ_COMPRA', 'TJ_EFECTIVO'] as $code) {
        $e = TelegramEventSetting::create(['bot_id' => $bot->id, 'event_code' => $code, 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $admin->id]);
        $e->destinations()->attach($dest->id, ['destination_revision' => 1]);
    }
    foreach (['BOT_CHECK' => $bot->id, 'DESTINATION_CHECK' => $dest->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $admin->id, 'actor_name' => $admin->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['after' => ['revision' => 1], 'bot_revision' => 1, 'status' => 'CONNECTED'], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }

    return [$admin, $cashier, $next, $products, $account];
}
function tgCardOpen(User $cashier): CardStoreCount
{
    return app(RecordCardStoreCountAction::class)->open($cashier, app(CardStoreSessionQuery::class)->form($cashier, 'OPEN'));
}
function tgCardBuyData(array $products, string $company = 'TIGO'): array
{
    return ['company' => $company, 'lines' => [['product_id' => $products[$company][0]->id, 'quantity' => 2, 'unit_price' => match ($company) {
        'TIGO' => '9.40','VIVA' => '9.30',default => '9.17'
    }], ['product_id' => $products[$company][1]->id, 'quantity' => 1, 'unit_price' => match ($company) {
        'TIGO' => '18.80','VIVA' => '39.50',default => '26.88'
    }]], 'request_key' => (string) Str::uuid()];
}
function tgCardDepositData(CardDepositAccount $account): array
{
    return ['account_id' => $account->id, 'account_revision' => 1, 'amount' => '250.50', 'operation_reference' => 'OPERACION-PRUEBA', 'request_key' => (string) Str::uuid()];
}
function tgCardOperation(string $kind, User $cashier, array $products, CardDepositAccount $account): Closure
{
    if ($kind === 'COUNT_OPEN') {
        $data = app(CardStoreSessionQuery::class)->form($cashier, 'OPEN');

        return fn () => app(RecordCardStoreCountAction::class)->open($cashier, $data);
    }
    tgCardOpen($cashier);
    $data = match ($kind) {
        'COUNT_CLOSE' => app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE'),'PURCHASE_BATCH' => tgCardBuyData($products),'PURCHASE' => ['product_id' => $products['TIGO'][0]->id, 'quantity' => 2, 'unit_price' => '9.40', 'request_key' => (string) Str::uuid()],default => tgCardDepositData($account)
    };

    return fn () => match ($kind) {
        'COUNT_CLOSE' => app(RecordCardStoreCountAction::class)->close($cashier, $data),'PURCHASE_BATCH' => app(RecordCardStockBatchAction::class)->purchase($cashier, $data),'PURCHASE' => app(RecordCardPurchaseAction::class)->execute($cashier, $data),default => app(RecordCardCashDepositAction::class)->execute($cashier, $data)
    };
}
function tgCardState(): array
{
    return [CardStore::sole()->only(['cash_balance_cents', 'revision']), CardStock::orderBy('id')->pluck('quantity')->all(), CardStoreCount::count(), CardStoreSession::where('active_slot', 1)->count(), CardInventoryMovement::count(), CardInventoryCommand::count(), EconomicIncident::count(), CardStoreReserve::count()];
}

it('omits all scoped card events while paused and does not resend on source replay', function () {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture(false);
    $open = app(CardStoreSessionQuery::class)->form($cashier, 'OPEN');
    app(RecordCardStoreCountAction::class)->open($cashier, $open);
    $buy = tgCardBuyData($products);
    app(RecordCardStockBatchAction::class)->purchase($cashier, $buy);
    $deposit = tgCardDepositData($account);
    app(RecordCardCashDepositAction::class)->execute($cashier, $deposit);
    $close = app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE');
    app(RecordCardStoreCountAction::class)->close($cashier, $close);
    $state = tgCardState();
    config(['telegram.sending_enabled' => true]);
    app(RecordCardStoreCountAction::class)->open($cashier, $open);
    app(RecordCardStockBatchAction::class)->purchase($cashier, $buy);
    app(RecordCardCashDepositAction::class)->execute($cashier, $deposit);
    app(RecordCardStoreCountAction::class)->close($cashier, $close);
    expect(TelegramDispatch::count())->toBe(4);
    expect(TelegramDispatch::where('status', 'OMITIDA')->whereNull('message')->count())->toBe(4);
    expect(TelegramDelivery::count())->toBe(0);
    expect(tgCardState())->toBe($state);
    expect(CardStore::sole()->cash_balance_cents)->toBe(71190);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('groups all purchased cuts in one saved notice and preserves historical prices', function (string $company, string $total, string $cash, int $balance) {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    tgCardOpen($cashier);
    $data = tgCardBuyData($products, $company);
    $result = app(RecordCardStockBatchAction::class)->purchase($cashier, $data);
    expect(app(RecordCardStockBatchAction::class)->purchase($cashier, $data))->toBe($result);
    $notice = TelegramDispatch::where('event_code', 'TJ_COMPRA')->sole();
    expect($notice->module)->toBe('TARJETAS');
    expect($notice->message)->toContain('Compra registrada', 'Empresa: '.$company, 'Tarjetas compradas: 3', 'Total pagado: Bs '.$total, 'Efectivo disponible posterior: Bs '.$cash, 'Lili prueba', '29/10/2026 08:00', 'Bolivia');
    if ($company === 'VIVA') {
        expect($notice->message)->toContain('Cabina Viva de Bs 40');
    }
    expect(count($result['movement_ids']))->toBe(2);
    expect(TelegramDispatch::count())->toBe(2);
    expect(CardStore::sole()->cash_balance_cents)->toBe($balance);
    expect(CardStock::where('card_product_id', $products[$company][0]->id)->sole()->quantity)->toBe(12);
    $text = $notice->message;
    $products[$company][0]->update(['purchase_price_cents' => 999]);
    $cashier->update(['name' => 'Nombre posterior']);
    app(RecordCardStockBatchAction::class)->purchase($cashier, $data);
    expect($notice->fresh()->message)->toBe($text);
    expect(TelegramDispatch::count())->toBe(2);
    Http::assertNothingSent();
})->with([['TIGO', '37.60', '962.40', 96240], ['VIVA', '58.10', '941.90', 94190], ['ENTEL', '45.22', '954.78', 95478]]);

it('announces the legacy single cut purchase once without depending on batch screens', function () {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    $run = tgCardOperation('PURCHASE', $cashier, $products, $account);
    $run();
    $run();
    expect(TelegramDispatch::where('event_code', 'TJ_COMPRA')->sole()->message)->toContain('Tarjetas compradas: 2', 'Total pagado: Bs 18.80', 'Efectivo disponible posterior: Bs 981.20');
    expect(CardInventoryMovement::where('kind', 'PURCHASE')->count())->toBe(1);
    expect(TelegramDispatch::count())->toBe(2);
    Http::assertNothingSent();
});

it('shows saved cash and no unchanged stock rows for ordinary card counts', function (string $kind) {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    if ($kind === 'CLOSE') {
        tgCardOpen($cashier);
    }
    $data = app(CardStoreSessionQuery::class)->form($cashier, $kind);
    $count = $kind === 'OPEN' ? app(RecordCardStoreCountAction::class)->open($cashier, $data) : app(RecordCardStoreCountAction::class)->close($cashier, $data);
    $message = TelegramDispatch::where('event_code', $kind === 'OPEN' ? 'TJ_APERTURA' : 'TJ_CIERRE')->sole()->message;
    expect($message)->toContain($kind === 'OPEN' ? 'Apertura registrada' : 'Cierre registrado', 'Efectivo esperado: Bs 1000', 'Efectivo contado: Bs 1000', 'Efectivo disponible: Bs 1000', 'Faltante total registrado: Bs 0', 'Sin diferencias de stock', 'Lili prueba', 'Conteo #'.$count->id)->not->toContain('TIGO · Bs 10', 'Sin configurar');
    expect(EconomicIncident::count())->toBe(0);
    Http::assertNothingSent();
})->with(['OPEN', 'CLOSE']);

it('includes complete shortages and reserved surpluses without netting or duplicate incident notices', function (string $kind) {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    if ($kind === 'CLOSE') {
        tgCardOpen($cashier);
    }
    $data = app(CardStoreSessionQuery::class)->form($cashier, $kind);
    $data['cash'] = '995';
    $data['reason'] = 'Diferencias de prueba';
    foreach ($data['stocks'] as &$line) {
        if ($line['product_id'] === $products['TIGO'][0]->id) {
            $line['quantity'] = 9;
        }if ($line['product_id'] === $products['TIGO'][1]->id) {
            $line['quantity'] = 11;
        }
    }unset($line);
    $count = $kind === 'OPEN' ? app(RecordCardStoreCountAction::class)->open($cashier, $data) : app(RecordCardStoreCountAction::class)->close($cashier, $data);
    $message = TelegramDispatch::where('event_code', $kind === 'OPEN' ? 'TJ_APERTURA' : 'TJ_CIERRE')->sole()->message;
    expect($message)->toContain('Faltante total registrado: Bs 15', 'TIGO · Bs 10', 'faltan 1', 'TIGO · Bs 20', 'sobran 1', 'Tarjetas reservadas: 1', 'No compensa faltantes', $kind === 'OPEN' ? 'Admin prueba' : 'Lili prueba');
    expect(EconomicIncident::sum('amount_cents'))->toBe(1500);
    expect(EconomicIncident::where('user_id', $kind === 'OPEN' ? $admin->id : $cashier->id)->count())->toBe(2);
    expect(CardStoreReserve::count())->toBe(1);
    expect($count->shortage_cents)->toBe(1500);
    $caption = TelegramDispatch::where('source_reference', 'count:'.$count->id)->sole()->media['caption'];
    expect($caption)->toContain('Faltante total registrado: Bs 15', 'Tarjetas reservadas: 1', 'Efectivo disponible:', $kind === 'OPEN' ? 'Responsable anterior/inicial: Admin prueba' : 'Responsable del turno: Lili prueba');
    expect(TelegramDispatch::whereIn('event_code', ['TJ_INCIDENCIA', 'TJ_RESERVA'])->count())->toBe(0);
    $reserved = CardStoreReserve::sole();
    expect($kind === 'OPEN' ? $reserved->expires_at === null : $reserved->expires_at !== null)->toBeTrue();
    Http::assertNothingSent();
})->with(['OPEN', 'CLOSE']);

it('attributes a new handover shortage to the previous cashier without charging an old shortage again', function () {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    tgCardOpen($cashier);
    $close = app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE');
    $close['cash'] = '995';
    app(RecordCardStoreCountAction::class)->close($cashier, $close);
    $open = app(CardStoreSessionQuery::class)->form($next, 'OPEN');
    $open['cash'] = '994';
    $count = app(RecordCardStoreCountAction::class)->open($next, $open);
    app(RecordCardStoreCountAction::class)->open($next, $open);
    $notice = TelegramDispatch::where('source_reference', 'count:'.$count->id)->sole();
    expect($notice->message)->toContain('Ana prueba', 'Responsable anterior/inicial: Lili prueba', 'Faltante total registrado: Bs 1');
    expect($notice->media['caption'])->toContain('Responsable anterior/inicial: Lili prueba', 'Faltante total registrado: Bs 1');
    expect(EconomicIncident::sum('amount_cents'))->toBe(600);
    expect(EconomicIncident::where('user_id', $cashier->id)->count())->toBe(2);
    expect(EconomicIncident::where('user_id', $next->id)->count())->toBe(0);
    expect(TelegramDispatch::count())->toBe(3);
    Http::assertNothingSent();
});

it('reports cash surplus as reserved and never available or offsetting shortages', function () {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    $open = app(CardStoreSessionQuery::class)->form($cashier, 'OPEN');
    $open['cash'] = '1005';
    $open['reason'] = 'Hallazgo de prueba';
    app(RecordCardStoreCountAction::class)->open($cashier, $open);
    expect(TelegramDispatch::sole()->message)->toContain('Efectivo reservado: Bs 5', 'Efectivo disponible: Bs 1000', 'Sobrante de efectivo: Bs 5', 'Revisar reservas');
    $close = app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE');
    app(RecordCardStoreCountAction::class)->close($cashier, $close);
    expect(TelegramDispatch::where('event_code', 'TJ_CIERRE')->sole()->message)->toContain('Efectivo esperado: Bs 1005', 'Efectivo reservado: Bs 5', 'Efectivo disponible: Bs 1000');
    expect(TelegramDispatch::where('event_code', 'TJ_CIERRE')->sole()->media['caption'])->toContain('Efectivo reservado: Bs 5', 'Efectivo disponible: Bs 1000');
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000);
    expect(CardStoreReserve::count())->toBe(1);
    expect(EconomicIncident::count())->toBe(0);
    Http::assertNothingSent();
});

it('uses the frozen deposit destination and optional operation without exposing account secrets', function (bool $reference) {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    tgCardOpen($cashier);
    $data = tgCardDepositData($account);
    if (! $reference) {
        unset($data['operation_reference']);
    }
    $movement = app(RecordCardCashDepositAction::class)->execute($cashier, $data);
    $notice = TelegramDispatch::where('event_code', 'TJ_EFECTIVO')->sole();
    expect($notice->message)->toContain('Depósito a Administración registrado', 'Importe depositado: Bs 250.50', 'Efectivo disponible posterior: Bs 749.50', 'Cuenta de prueba', 'Banco prueba', $reference ? 'OPERACION-PRUEBA' : 'Sin número de operación')->not->toContain('NUMERO-PRIVADO', 'TITULAR-PRIVADO', 'PRIVADO.png', 'Yasta');
    $text = $notice->message;
    $account->update(['name' => 'Otra cuenta', 'is_active' => false, 'revision' => 2]);
    expect(app(RecordCardCashDepositAction::class)->execute($cashier, $data)->id)->toBe($movement->id);
    expect($notice->fresh()->message)->toBe($text);
    expect(TelegramDispatch::count())->toBe(2);
    expect(CardStock::sum('quantity'))->toBe(60);
    Http::assertNothingSent();
})->with([false, true]);

it('does not emit Telegram for previews routine sales funding or administrative card deliveries', function () {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    app(CardStoreSessionQuery::class)->form($cashier, 'OPEN');
    expect(TelegramDispatch::count())->toBe(0);
    tgCardOpen($cashier);
    app(RecordCardSaleAction::class)->execute($cashier, ['mode' => 'NORMAL', 'payment_method' => 'CASH', 'lines' => [['product_id' => $products['TIGO'][0]->id, 'quantity' => 1, 'unit_price' => '10']], 'request_key' => (string) Str::uuid()]);
    app(RecordCardCashFundingAction::class)->execute($cashier, ['amount' => '10', 'request_key' => (string) Str::uuid()]);
    app(RecordCardStockBatchAction::class)->delivery($cashier, ['company' => 'ENTEL', 'lines' => [['product_id' => $products['ENTEL'][0]->id, 'quantity' => 2]], 'request_key' => (string) Str::uuid()]);
    expect(TelegramDispatch::count())->toBe(1);
    Http::assertNothingSent();
});

it('does not notify invalid unauthorized insufficient or stale operations', function () {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    $outsider = User::factory()->create();
    $open = app(CardStoreSessionQuery::class)->form($cashier, 'OPEN');
    expect(fn () => app(RecordCardStoreCountAction::class)->open($outsider, $open))->toThrow(AuthorizationException::class);
    tgCardOpen($cashier);
    $close = app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE');
    expect(fn () => app(RecordCardStoreCountAction::class)->close($next, $close))->toThrow(ValidationException::class);
    $buy = tgCardBuyData($products);
    $buy['lines'][0]['quantity'] = 1000;
    expect(fn () => app(RecordCardStockBatchAction::class)->purchase($cashier, $buy))->toThrow(ValidationException::class);
    $deposit = tgCardDepositData($account);
    $deposit['amount'] = '1001';
    expect(fn () => app(RecordCardCashDepositAction::class)->execute($cashier, $deposit))->toThrow(ValidationException::class);
    $deposit = tgCardDepositData($account);
    $deposit['account_revision'] = 99;
    expect(fn () => app(RecordCardCashDepositAction::class)->execute($cashier, $deposit))->toThrow(ValidationException::class);
    expect(TelegramDispatch::count())->toBe(1);
    expect(CardStore::sole()->cash_balance_cents)->toBe(100000);
    Http::assertNothingSent();
});

it('rolls back notices with an interrupted source command and confirms once on retry', function (string $kind) {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    $run = tgCardOperation($kind, $cashier, $products, $account);
    $state = tgCardState();
    $count = TelegramDispatch::count();
    DB::statement("CREATE TRIGGER interrupt_cards BEFORE INSERT ON card_inventory_commands WHEN NEW.action = '$kind' BEGIN SELECT RAISE(ABORT,'interruption'); END");
    expect($run)->toThrow(QueryException::class);
    expect(tgCardState())->toBe($state);
    expect(TelegramDispatch::count())->toBe($count);
    DB::statement('DROP TRIGGER interrupt_cards');
    $run();
    $run();
    expect(TelegramDispatch::count())->toBe($count + 1);
    Http::assertNothingSent();
})->with(['COUNT_OPEN', 'COUNT_CLOSE', 'PURCHASE', 'PURCHASE_BATCH', 'CASH_ADMIN_DEPOSIT']);

it('rolls back financial state when the Telegram intention cannot be saved', function (string $kind, string $code) {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    $run = tgCardOperation($kind, $cashier, $products, $account);
    $state = tgCardState();
    $count = TelegramDispatch::count();
    DB::statement("CREATE TRIGGER fail_cards_notice BEFORE INSERT ON telegram_dispatches WHEN NEW.event_code = '$code' BEGIN SELECT RAISE(ABORT,'outbox unavailable'); END");
    expect($run)->toThrow(QueryException::class);
    expect(tgCardState())->toBe($state);
    expect(TelegramDispatch::count())->toBe($count);
    DB::statement('DROP TRIGGER fail_cards_notice');
    $run();
    $run();
    expect(TelegramDispatch::count())->toBe($count + 1);
    Http::assertNothingSent();
})->with([['COUNT_OPEN', 'TJ_APERTURA'], ['COUNT_CLOSE', 'TJ_CIERRE'], ['PURCHASE_BATCH', 'TJ_COMPRA'], ['CASH_ADMIN_DEPOSIT', 'TJ_EFECTIVO']]);

it('retries only a simulated delivery without repeating card sessions stock or cash', function (string $code) {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    tgCardOpen($cashier);
    app(RecordCardStockBatchAction::class)->purchase($cashier, tgCardBuyData($products));
    app(RecordCardCashDepositAction::class)->execute($cashier, tgCardDepositData($account));
    app(RecordCardStoreCountAction::class)->close($cashier, app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE'));
    $state = tgCardState();
    $delivery = TelegramDispatch::where('event_code', $code)->sole()->deliveries()->sole();
    Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false, 'error_code' => 500], 500)->push(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => -100123]]])]);
    $process = app(ProcessTelegramDeliveryAction::class);
    $process->execute($delivery->id);
    test()->travel(60)->seconds();
    $process->execute($delivery->id);
    $process->execute($delivery->id);
    expect($delivery->fresh()->status)->toBe('ENVIADA');
    expect(tgCardState())->toBe($state);
    expect(TelegramDispatch::count())->toBe(4);
    Http::assertSentCount(2);
})->with(['TJ_APERTURA', 'TJ_CIERRE', 'TJ_COMPRA', 'TJ_EFECTIVO']);

it('does not backfill historical card facts when their existing commands are replayed', function () {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture(false);
    $open = app(CardStoreSessionQuery::class)->form($cashier, 'OPEN');
    app(RecordCardStoreCountAction::class)->open($cashier, $open);
    $buy = tgCardBuyData($products);
    app(RecordCardStockBatchAction::class)->purchase($cashier, $buy);
    $deposit = tgCardDepositData($account);
    app(RecordCardCashDepositAction::class)->execute($cashier, $deposit);
    $close = app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE');
    app(RecordCardStoreCountAction::class)->close($cashier, $close);
    $state = tgCardState();
    TelegramDispatch::query()->delete();
    config(['telegram.sending_enabled' => true]);
    app(RecordCardStoreCountAction::class)->open($cashier, $open);
    app(RecordCardStockBatchAction::class)->purchase($cashier, $buy);
    app(RecordCardCashDepositAction::class)->execute($cashier, $deposit);
    app(RecordCardStoreCountAction::class)->close($cashier, $close);
    expect(TelegramDispatch::count())->toBe(0);
    expect(tgCardState())->toBe($state);
    Http::assertNothingSent();
});

it('keeps the saved card count and durable delivery if queue publishing fails', function () {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    Queue::partialMock()->shouldReceive('connection')->with('telegram')->andThrow(new RuntimeException('queue unavailable'));
    $count = tgCardOpen($cashier);
    expect(CardStoreCount::count())->toBe(1);
    expect(CardStoreSession::where('active_slot', 1)->count())->toBe(1);
    expect(TelegramDispatch::sole()->deliveries()->sole()->status)->toBe('PENDIENTE');
    expect(TelegramDispatch::sole()->deliveries()->sole()->queued_at)->toBeNull();
    Http::assertNothingSent();
});

it('rolls back automatic debts and surplus reserves with a failed closing notice', function () {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    tgCardOpen($cashier);
    $data = app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE');
    $data['cash'] = '995';
    $data['reason'] = 'Diferencias de prueba';
    foreach ($data['stocks'] as &$line) {
        if ($line['product_id'] === $products['TIGO'][0]->id) {
            $line['quantity'] = 9;
        }if ($line['product_id'] === $products['TIGO'][1]->id) {
            $line['quantity'] = 11;
        }
    }unset($line);
    $state = tgCardState();
    DB::statement("CREATE TRIGGER fail_card_debt_notice BEFORE INSERT ON telegram_dispatches WHEN NEW.event_code = 'TJ_CIERRE' BEGIN SELECT RAISE(ABORT,'interruption'); END");
    expect(fn () => app(RecordCardStoreCountAction::class)->close($cashier, $data))->toThrow(QueryException::class);
    expect(tgCardState())->toBe($state);
    expect(TelegramDispatch::count())->toBe(1);
    DB::statement('DROP TRIGGER fail_card_debt_notice');
    app(RecordCardStoreCountAction::class)->close($cashier, $data);
    app(RecordCardStoreCountAction::class)->close($cashier, $data);
    expect(EconomicIncident::count())->toBe(2);
    expect(EconomicIncident::sum('amount_cents'))->toBe(1500);
    expect(CardStoreReserve::count())->toBe(1);
    expect(TelegramDispatch::count())->toBe(2);
    tgCardOpen($next);
    expect(EconomicIncident::count())->toBe(2);
    expect(CardStoreReserve::count())->toBe(1);
    Http::assertNothingSent();
});

it('bounds large purchase and count details while retaining the totals of every cut', function () {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    $store = CardStore::sole();
    $ids = [];
    $lines = [];
    for ($i = 0; $i < 100; $i++) {
        $p = CardProduct::create(['company' => 'TIGO', 'face_value_cents' => 5000 + $i * 100, 'purchase_price_cents' => 100, 'normal_price_cents' => 5000 + $i * 100, 'wholesale_price_cents' => 5000 + $i * 100, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 0]);
        $ids[] = $p->id;
        $lines[] = ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => '1'];
    }
    tgCardOpen($cashier);
    app(RecordCardStockBatchAction::class)->purchase($cashier, ['company' => 'TIGO', 'lines' => $lines, 'request_key' => (string) Str::uuid()]);
    $purchaseNotice = TelegramDispatch::where('event_code', 'TJ_COMPRA')->sole();
    $purchase = $purchaseNotice->message;
    expect($purchaseNotice->media['caption'])->toContain('Tarjetas compradas: 100', 'Total pagado: Bs 100', 'Efectivo disponible posterior: Bs 900');
    expect($purchase)->toContain('Tarjetas compradas: 100', 'Total pagado: Bs 100', 'Efectivo disponible posterior: Bs 900', '92 cortes más');
    $close = app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE');
    $close['reason'] = 'Sobrante de prueba';
    foreach ($close['stocks'] as &$row) {
        if (in_array($row['product_id'], $ids, true)) {
            $row['quantity'] = 2;
        }
    }unset($row);
    app(RecordCardStoreCountAction::class)->close($cashier, $close);
    $message = TelegramDispatch::where('event_code', 'TJ_CIERRE')->sole()->message;
    expect($message)->toContain('Tarjetas esperadas: 160', 'Contadas: 260', 'Tarjetas reservadas: 100', '92 cortes más');
    foreach (TelegramDispatch::all() as $d) {
        expect(mb_strlen($d->message))->toBeLessThanOrEqual(4096);
    }
    expect(CardStock::sum('quantity'))->toBe(160);
    expect(CardStore::sole()->cash_balance_cents)->toBe(90000);
    expect(EconomicIncident::count())->toBe(0);
    Http::assertNothingSent();
});

it('freezes a company photo for each whole purchase and sends only one notice per batch', function ($company, $filename) {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    tgCardOpen($cashier);
    $buy = tgCardBuyData($products, $company);
    app(RecordCardStockBatchAction::class)->purchase($cashier, $buy);
    $notice = TelegramDispatch::where('event_code', 'TJ_COMPRA')->sole();
    $media = $notice->media;
    expect($media['filename'])->toBe($filename)->and($media['caption'])->toContain('Empresa: '.$company, 'Tarjetas compradas: 3', 'Total pagado:', 'Efectivo disponible posterior:');
    expect(DB::table('telegram_dispatches')->where('id', $notice->id)->value('media'))->not->toContain($filename, 'Compra registrada');
    $state = tgCardState();
    app(RecordCardStockBatchAction::class)->purchase($cashier, $buy);
    expect(tgCardState())->toBe($state);
    $token = TelegramBotSetting::where('module', 'TARJETAS')->sole()->token;
    Http::fake(['https://api.telegram.org/bot'.$token.'/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => -100123]]])]);
    $delivery = $notice->deliveries()->sole();
    $cashier->update(['name' => 'Nombre posterior']);
    app(ProcessTelegramDeliveryAction::class)->execute($delivery->id);
    app(ProcessTelegramDeliveryAction::class)->execute($delivery->id);
    expect($delivery->fresh()->status)->toBe('ENVIADA')->and($notice->fresh()->media)->toBe($media);
    expect(TelegramDispatch::where('event_code', 'TJ_COMPRA')->count())->toBe(1)->and(tgCardState())->toBe($state);
    Http::assertSentCount(1);
})->with([['ENTEL', 'entel.jpg'], ['TIGO', 'recaraga o cuentas tigo money.png'], ['VIVA', 'recarga viva.jpg']]);

it('keeps general card event images frozen and omits them while paused', function ($enabled) {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture($enabled);
    tgCardOpen($cashier);
    $deposit = tgCardDepositData($account);
    app(RecordCardCashDepositAction::class)->execute($cashier, $deposit);
    $closing = app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE');
    app(RecordCardStoreCountAction::class)->close($cashier, $closing);
    foreach (TelegramDispatch::all() as $notice) {
        if ($enabled) {
            expect($notice->media['filename'])->toBe('logoReciboPrestamo.png')->and($notice->media['caption'])->not->toContain('NUMERO-PRIVADO', 'TITULAR-PRIVADO', 'PRIVADO.png');
        } else {
            expect($notice->media)->toBeNull();
        }
    }
    expect(TelegramDispatch::count())->toBe(3);
    Http::assertNothingSent();
})->with([false, true]);

it('preserves a historical text card notice when the adapter is replayed after the visual update', function () {
    [$admin,$cashier,$next,$products,$account] = tgCardFixture();
    tgCardOpen($cashier);
    $old = TelegramDispatch::sole();
    $old->update(['message' => 'Aviso histórico de Tarjetas', 'media' => null]);
    $command = CardInventoryCommand::where('action', 'COUNT_OPEN')->sole();
    $state = tgCardState();
    DB::transaction(fn () => app(RecordCardTelegramNoticeAction::class)->execute($command->id));
    expect($old->fresh()->message)->toBe('Aviso histórico de Tarjetas')->and($old->fresh()->media)->toBeNull();
    expect(TelegramDispatch::count())->toBe(1)->and(TelegramDelivery::count())->toBe(1)->and(tgCardState())->toBe($state);
    Http::assertNothingSent();
});
