<?php

use App\Domain\Accesorios\Actions\RegisterAccessorySaleAction;
use App\Domain\Accesorios\Actions\RegisterPhoneUnitAction;
use App\Domain\Accesorios\Actions\ReservePhoneAction;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Accesorios\Models\PhoneImei;
use App\Domain\Accesorios\Models\PhoneReservation;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Models\PhoneWarranty;
use App\Domain\Cajas\Actions\ConfigureCashBoxAction;
use App\Domain\Cajas\Actions\ConfirmCashBoxClosingAction;
use App\Domain\Cajas\Actions\OpenCashBoxAction;
use App\Domain\Cajas\Actions\RecordQuickCashBoxCommissionAction;
use App\Domain\Cajas\Actions\SetCashBoxCommissionAccountAction;
use App\Domain\Cajas\Actions\StartCashBoxAccessAction;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Comisiones\Actions\RegisterCommissionPaymentAction;
use App\Domain\Comisiones\Actions\VoidCommissionPaymentAction;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Comisiones\Models\CommissionPayment;
use App\Domain\Comisiones\Models\CommissionPaymentReversal;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Notificaciones\Actions\ProcessTelegramDeliveryAction;
use App\Domain\Notificaciones\Actions\RetryTelegramDeliveryAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDeliveryAttempt;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Services\TelegramDeliveryPublisher;
use App\Domain\Prestamos\Actions\ChangeLoanTermAction;
use App\Domain\Prestamos\Actions\ManageLoanFundAction;
use App\Domain\Prestamos\Actions\RegisterLoanAction;
use App\Domain\Prestamos\Actions\SaveLoanTypeAction;
use App\Domain\Prestamos\Actions\SettleLoanAction;
use App\Domain\Prestamos\Models\Borrower;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanSettlement;
use App\Domain\Prestamos\Models\LoanTermChange;
use App\Domain\Tarjetas\Actions\InitializeCardStoreAction;
use App\Domain\Tarjetas\Actions\RecordCardCashDepositAction;
use App\Domain\Tarjetas\Actions\RecordCardStockBatchAction;
use App\Domain\Tarjetas\Actions\RecordCardStoreCountAction;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Domain\Tarjetas\Models\CardInventoryCommand;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Jobs\SendTelegramDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Prestamos\LoanPermissionsSeeder;
use Database\Seeders\Tarjetas\CardDepositsPermissionsSeeder;
use Database\Seeders\Tarjetas\CardSalesPermissionsSeeder;
use Database\Seeders\Tarjetas\CardStoreSessionsPermissionsSeeder;
use Database\Seeders\Tarjetas\TarjetasPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->artisan('migrate:fresh')->assertExitCode(0);
});
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

function fourBotSetup(bool $enabled = true, bool $shared = false): array
{
    test()->travelTo(CarbonImmutable::parse('2026-10-29 20:00', 'America/La_Paz'));
    config(['telegram.sending_enabled' => $enabled, 'cajas.schedule_controls_enabled' => false]);
    Http::preventStrayRequests();
    test()->seed([UsuariosPermissionsSeeder::class, CajasPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class, LoanPermissionsSeeder::class, TarjetasPermissionsSeeder::class, CardSalesPermissionsSeeder::class, CardStoreSessionsPermissionsSeeder::class, CardDepositsPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    $admin = User::factory()->create(['name' => 'Admin conjunto', 'pin_hash' => Hash::make('1234')]);
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['name' => 'Lili conjunto', 'pin_hash' => Hash::make('1234')]);
    $cashier->assignRole('cajera');
    $manager = User::factory()->create(['name' => 'Encargado conjunto']);
    $manager->assignRole('encargado');
    $bots = [];
    $primaries = [];
    $managers = [];
    $events = [];
    $catalog = ['CAJAS' => ['CJ_APERTURA', 'CJ_CIERRE'], 'PRESTAMOS' => ['PR_ALTA', 'PR_RENOVACION', 'PR_PAGO_ENTREGA'], 'TARJETAS' => ['TJ_APERTURA', 'TJ_COMPRA', 'TJ_EFECTIVO', 'TJ_CIERRE'], 'ACCESORIOS' => ['AC_VENTA_TELEFONO', 'AC_COMISION_PAGO', 'AC_COMISION_ANULACION']];
    $index = 0;
    foreach ($catalog as $module => $codes) {
        $index++;
        $token = (910000 + $index).':'.str_repeat(chr(64 + $index), 35);
        $b = TelegramBotSetting::create(['module' => $module, 'name' => 'Bot simulado '.$module, 'token' => $token, 'token_fingerprint' => hash('sha256', $token), 'is_enabled' => true, 'revision' => 1, 'updated_by' => $admin->id]);
        $bots[$module] = $b;
        $primary = TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $admin->id, 'name' => 'Admin '.$module, 'chat_id' => $shared ? '-1009000' : (string) (-1009000 - $index), 'kind' => 'GROUP', 'is_enabled' => true, 'revision' => 1, 'updated_by' => $admin->id]);
        $primaries[$module] = $primary;
        $optional = TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $manager->id, 'name' => 'Encargado '.$module, 'chat_id' => '-1009900', 'kind' => 'GROUP', 'is_enabled' => true, 'revision' => 1, 'updated_by' => $admin->id]);
        $managers[$module] = $optional;
        TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $admin->id, 'name' => 'No asignado '.$module, 'chat_id' => (string) (-1008000 - $index), 'kind' => 'GROUP', 'is_enabled' => true, 'revision' => 1, 'updated_by' => $admin->id]);
        foreach ($codes as $code) {
            $e = TelegramEventSetting::create(['bot_id' => $b->id, 'event_code' => $code, 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $admin->id]);
            $events[$code] = $e;
            $e->destinations()->attach($primary->id, ['destination_revision' => 1]);
            if (! $shared && in_array($code, ['CJ_CIERRE', 'TJ_COMPRA', 'AC_COMISION_PAGO'], true)) {
                $e->destinations()->attach($optional->id, ['destination_revision' => 1]);
            }
        }
        foreach ([['BOT_CHECK', $b->id], ['DESTINATION_CHECK', $primary->id], ['DESTINATION_CHECK', $optional->id]] as [$kind,$id]) {
            TelegramConfigurationChange::create(['actor_id' => $admin->id, 'actor_name' => $admin->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['after' => ['revision' => 1], 'bot_revision' => 1, 'status' => 'CONNECTED'], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $module.$kind.$id), 'created_at' => now()]);
        }
    }

    return compact('admin', 'cashier', 'manager', 'bots', 'primaries', 'managers', 'events', 'catalog', 'shared');
}

function fourBotBusinessFlow(array $f): array
{
    $admin = $f['admin'];
    $cashier = $f['cashier'];
    $replays = [];
    $box = CashBox::factory()->create(['name' => 'Caja conjunta de prueba']);
    $accounts = [];
    foreach (CashBoxComponents::forType('BCP') as $code => $name) {
        $accounts[$code] = ['name' => $name, 'initial' => match ($code) {
            'bcp' => '15000','efectivo' => '2000',default => '3000'
        }];
    }
    app(ConfigureCashBoxAction::class)->execute($admin, $box, ['accounts' => $accounts, 'base_capital' => '20000', 'shifts' => CashBoxComponents::defaultShifts(), 'expected_revision' => $box->revision, 'reason' => 'Configuración simulada', 'request_key' => (string) Str::uuid()]);
    $access = app(StartCashBoxAccessAction::class)->execute($cashier, $box, '1234', (string) Str::uuid());
    $open = ['balances' => ['bcp' => '15000', 'efectivo' => '2000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()];
    $session = app(OpenCashBoxAction::class)->execute($cashier, $access->id, $open);
    expect(app(OpenCashBoxAction::class)->execute($cashier, $access->id, $open)->id)->toBe($session->id);
    $replays[] = function () use ($cashier, $access, $open): void {
        expect(fn () => app(OpenCashBoxAction::class)->execute($cashier, $access->id, $open))->toThrow(ValidationException::class);
    };
    app(RecordQuickCashBoxCommissionAction::class)->execute($cashier, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $destination = app(SetCashBoxCommissionAccountAction::class)->execute($admin, ['destination' => '70000000', 'holder_name' => 'Admin ficticio', 'reason' => 'Cuenta ficticia', 'pin' => '1234', 'request_key' => (string) Str::uuid()]);
    $close = ['period' => 'tarde', 'balances' => ['efectivo' => '2030', 'bcp' => '15000', 'yape' => '3000'], 'readings' => ['general' => '100', 'fixed' => '50'], 'expected_revision' => $session->fresh()->revision, 'liquidate' => true, 'account_id' => $destination, 'amount_cents' => 3000, 'transferred' => true, 'operation_reference' => 'TRANSFERENCIA-PRIVADA', 'reason' => 'Faltante simulado', 'cash_count' => null, 'request_key' => (string) Str::uuid()];
    app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $close);
    $replays[] = fn () => app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $close);

    $type = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa prueba', 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'INITIAL', 'amount' => '1000', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $new = ['ci' => 'C327-CI-PRIVADO', 'name' => 'Cliente préstamo simulado', 'phone' => '77712345', 'type_id' => $type->id, 'type_revision' => 1, 'description' => 'Garrafa amarilla', 'serial' => 'SERIE-PRIVADA', 'capital' => '200', 'days' => 15, 'start_date' => '2026-10-29', 'request_key' => (string) Str::uuid()];
    $loan = app(RegisterLoanAction::class)->execute($cashier, $new);
    $replays[] = fn () => app(RegisterLoanAction::class)->execute($cashier, $new);
    $renew = ['kind' => 'RENEW', 'days' => 15, 'periods' => 1, 'expected_revision' => 0, 'expected_paid_cents' => 1500, 'payment_confirmed' => true, 'request_key' => (string) Str::uuid()];
    $change = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $renew);
    $replays[] = fn () => app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $renew);
    $settle = ['expected_revision' => $change->id, 'expected_paid_cents' => 21500, 'payment_confirmed' => true, 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()];
    app(SettleLoanAction::class)->execute($cashier, $loan->id, $settle);
    $replays[] = fn () => app(SettleLoanAction::class)->execute($cashier, $loan->id, $settle);

    $store = CardStore::create(['code' => 'TIENDA']);
    $products = [];
    $rows = [];
    foreach ([[1000, 940], [2000, 1880]] as [$face,$cost]) {
        $p = CardProduct::create(['company' => 'TIGO', 'face_value_cents' => $face, 'purchase_price_cents' => $cost, 'normal_price_cents' => $face, 'wholesale_price_cents' => $face, 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
        CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $p->id, 'quantity' => 0]);
        $products[] = $p;
        $rows[] = ['product_id' => $p->id, 'quantity' => 10];
    }
    app(InitializeCardStoreAction::class)->execute($admin, ['cash_amount' => '1000', 'stocks' => $rows, 'request_key' => (string) Str::uuid()]);
    $cardOpen = app(CardStoreSessionQuery::class)->form($cashier, 'OPEN');
    app(RecordCardStoreCountAction::class)->open($cashier, $cardOpen);
    $replays[] = fn () => app(RecordCardStoreCountAction::class)->open($cashier, $cardOpen);
    $buy = ['company' => 'TIGO', 'lines' => [['product_id' => $products[0]->id, 'quantity' => 2, 'unit_price' => '9.40'], ['product_id' => $products[1]->id, 'quantity' => 1, 'unit_price' => '18.80']], 'request_key' => (string) Str::uuid()];
    app(RecordCardStockBatchAction::class)->purchase($cashier, $buy);
    $replays[] = fn () => app(RecordCardStockBatchAction::class)->purchase($cashier, $buy);
    $bank = CardDepositAccount::create(['name' => 'Cuenta simulada', 'bank' => 'Banco prueba', 'number' => 'NUMERO-PRIVADO', 'holder' => 'TITULAR-PRIVADO', 'qr_path' => 'PRIVADO.png', 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id]);
    $deposit = ['account_id' => $bank->id, 'account_revision' => 1, 'amount' => '250.50', 'operation_reference' => 'OPERACION-SIMULADA', 'request_key' => (string) Str::uuid()];
    app(RecordCardCashDepositAction::class)->execute($cashier, $deposit);
    $replays[] = fn () => app(RecordCardCashDepositAction::class)->execute($cashier, $deposit);
    $cardClose = app(CardStoreSessionQuery::class)->form($cashier, 'CLOSE');
    app(RecordCardStoreCountAction::class)->close($cashier, $cardClose);
    $replays[] = fn () => app(RecordCardStoreCountAction::class)->close($cashier, $cardClose);

    $unit = app(RegisterPhoneUnitAction::class)->execute($admin, ['brand' => 'Samsung', 'model' => 'Galaxy prueba', 'ram_gb' => 8, 'storage_gb' => 256, 'condition' => 'USED', 'color' => 'Negro', 'quantity' => 3, 'purchase_cost' => '800', 'expenses' => '0', 'price_mode' => 'MANUAL', 'sale_price' => '950', 'expected_pricing_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $cart = (string) Str::uuid();
    $reservation = app(ReservePhoneAction::class)->execute($cashier, $unit->id, $cart, 5);
    $saleData = ['lines' => [], 'phone_lines' => [['unit_id' => $unit->id, 'reservation_id' => $reservation->id, 'expected_price_cents' => 95000, 'imei1' => '000000000100001', 'imei2' => '000000000200001']], 'customer' => ['ci' => 'C327-PRIVADO-AC', 'name' => 'Cliente privado combinado', 'phone' => '77712345', 'email' => 'privado@example.test', 'email2' => null], 'warranty_accepted' => true, 'payment_method' => 'CASH', 'cash_received' => true, 'request_key' => $cart];
    app(RegisterAccessorySaleAction::class)->execute($cashier, $saleData);
    $replays[] = fn () => app(RegisterAccessorySaleAction::class)->execute($cashier, $saleData);
    $pay = ['user_id' => $cashier->id, 'amount' => '20', 'expected_earned_cents' => 3000, 'expected_paid_cents' => 0, 'delivered' => true, 'reference' => 'REFERENCIA-PRIVADA', 'note' => 'NOTA-PRIVADA', 'request_key' => (string) Str::uuid()];
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, $pay);
    $replays[] = fn () => app(RegisterCommissionPaymentAction::class)->execute($admin, $pay);
    $void = ['payment_id' => $payment->id, 'confirmed' => true, 'reason' => 'Registro incorrecto simulado', 'request_key' => (string) Str::uuid()];
    app(VoidCommissionPaymentAction::class)->execute($admin, $void);
    $replays[] = fn () => app(VoidCommissionPaymentAction::class)->execute($admin, $void);

    return $replays;
}

function fourBotBusinessState(): array
{
    $models = [CashBoxSession::class, CashBoxClose::class, EconomicIncident::class, Loan::class, LoanFund::class, LoanTermChange::class, LoanSettlement::class, LoanMovement::class, Borrower::class, CardStore::class, CardStock::class, CardStoreCount::class, CardStoreSession::class, CardInventoryCommand::class, CardInventoryMovement::class, AccessorySale::class, AccessorySaleLine::class, CommercialFund::class, CommercialMovement::class, PhoneUnit::class, PhoneWarranty::class, PhoneReservation::class, PhoneImei::class, CommissionEntry::class, CommissionPayment::class, CommissionPaymentReversal::class];
    $state = [];
    foreach ($models as $class) {
        $table = (new $class)->getTable();
        $state[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
    }
    $state['cash_box_liquidations'] = hash('sha256', DB::table('cash_box_liquidations')->orderBy('id')->get()->toJson());

    return $state;
}
function fourBotReplay(array $replays): void
{
    foreach ($replays as $replay) {
        $replay();
        $replay();
    }
}
function fourBotDrain(bool $duplicate = false): int
{
    $handled = 0;
    while ($job = Queue::connection('telegram')->pop('telegram')) {
        if (++$handled > 100) {
            throw new RuntimeException('Unexpected queue growth');
        }
        $job->fire();
        if ($duplicate) {
            $job->fire();
        }$job->delete();
    }

    return $handled;
}
function fourBotPairs(array $f): array
{
    $pairs = [];
    foreach (TelegramDispatch::orderBy('id')->get() as $notice) {
        $module = $notice->module;
        $destinations = [$f['primaries'][$module]];
        if (! $f['shared'] && in_array($notice->event_code, ['CJ_CIERRE', 'TJ_COMPRA', 'AC_COMISION_PAGO'], true)) {
            $destinations[] = $f['managers'][$module];
        }
        foreach ($destinations as $dest) {
            $pairs[] = ['url' => 'https://api.telegram.org/bot'.$f['bots'][$module]->token.($notice->media ? '/sendPhoto' : '/sendMessage'), 'chat' => (string) $dest->chat_id, 'message' => $notice->media['caption'] ?? $notice->message, 'media' => $notice->media, 'code' => $notice->event_code, 'module' => $module, 'primary' => $dest->id === $f['primaries'][$module]->id];
        }
    }

    return $pairs;
}
function fourBotFake(array $f, callable $response): void
{
    $patterns = [];
    foreach ($f['bots'] as $bot) {
        $patterns['https://api.telegram.org/bot'.$bot->token.'/sendMessage'] = $response;
        $patterns['https://api.telegram.org/bot'.$bot->token.'/sendPhoto'] = $response;
    }
    Http::fake($patterns);
}
function fourBotPair(array $pairs, $request): array
{
    expect(DB::transactionLevel())->toBe(0);
    $data = $request->isMultipart() ? collect($request->data())->pluck('contents', 'name')->all() : $request->data();
    expect($data['protect_content'])->toBe($request->isMultipart() ? 'true' : true);
    if (! $request->isMultipart()) {
        expect($data['link_preview_options']['is_disabled'])->toBeTrue();
    }
    foreach ($pairs as $pair) {
        if ($request->url() === $pair['url'] && (string) $data['chat_id'] === $pair['chat'] && ($data['caption'] ?? $data['text']) === $pair['message']) {
            if ($pair['media']) {
                expect($request->hasFile('photo', base64_decode($pair['media']['content'], true)))->toBeTrue();
            }

            return $pair;
        }
    }
    throw new RuntimeException('The message was sent through an unexpected bot or destination');
}
function fourBotSuccess(array $pair): PromiseInterface
{
    return Http::response(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => (int) $pair['chat']]]]);
}
function fourBotIsRetryTarget(array $pair): bool
{
    return $pair['primary'] && in_array($pair['code'], ['CJ_CIERRE', 'PR_RENOVACION', 'TJ_COMPRA', 'AC_COMISION_ANULACION'], true);
}
function fourBotPairKey(array $pair): string
{
    return $pair['module'].':'.$pair['code'].':'.$pair['chat'];
}

it('keeps all twelve source facts omitted during pause and never queues them after source replay', function () {
    $f = fourBotSetup(false);
    $replays = fourBotBusinessFlow($f);
    $state = fourBotBusinessState();
    expect(TelegramDispatch::count())->toBe(12);
    expect(TelegramDispatch::where('status', 'OMITIDA')->whereNull('message')->count())->toBe(12);
    expect(TelegramDelivery::count())->toBe(0);
    expect(DB::table('jobs')->count())->toBe(0);
    config(['telegram.sending_enabled' => true]);
    fourBotReplay($replays);
    expect(fourBotBusinessState())->toBe($state);
    expect(TelegramDispatch::count())->toBe(12);
    expect(TelegramDelivery::count())->toBe(0);
    expect(app(TelegramDeliveryPublisher::class)->due())->toBe(0);
    Http::assertNothingSent();
});

it('routes every saved source message through its own bot and explicit destinations using real database jobs', function () {
    $f = fourBotSetup();
    $f['events']['CJ_APERTURA']->destinations()->attach($f['primaries']['PRESTAMOS']->id, ['destination_revision' => 1]);
    $replays = fourBotBusinessFlow($f);
    $state = fourBotBusinessState();
    $pairs = fourBotPairs($f);
    expect(TelegramDispatch::count())->toBe(12);
    expect(TelegramDelivery::count())->toBe(15);
    expect(DB::table('jobs')->where('queue', 'telegram')->count())->toBe(15);
    $expected = [
        'CJ_APERTURA' => ['Apertura registrada', 'Total contado: Bs 20000'],
        'CJ_CIERRE' => ['Faltante registrado: Bs 20', 'Liquidación realizada: Bs 30', 'Saldo posterior: Bs 20000'],
        'PR_ALTA' => ['Capital: Bs 200', 'Interés: Bs 15', 'Vencimiento: 12/11/2026'],
        'PR_RENOVACION' => ['Interés cobrado: Bs 15', 'Interés pendiente: Bs 15', 'Nuevo vencimiento: 27/11/2026'],
        'PR_PAGO_ENTREGA' => ['Capital cobrado: Bs 200', 'Interés cobrado: Bs 15', 'Total cobrado: Bs 215'],
        'TJ_APERTURA' => ['Efectivo contado: Bs 1000'],
        'TJ_COMPRA' => ['Empresa: TIGO', 'Tarjetas compradas: 3', 'Total pagado: Bs 37.60'],
        'TJ_EFECTIVO' => ['Importe depositado: Bs 250.50', 'Efectivo disponible posterior: Bs 711.90'],
        'TJ_CIERRE' => ['Efectivo contado: Bs 711.90'],
        'AC_VENTA_TELEFONO' => ['Samsung Galaxy prueba', 'Total cobrado: Bs 950'],
        'AC_COMISION_PAGO' => ['Importe pagado: Bs 20', 'Pendiente tras este pago: Bs 10'],
        'AC_COMISION_ANULACION' => ['Importe restituido: Bs 20', 'Pendiente tras esta anulación: Bs 30'],
    ];
    foreach (TelegramDispatch::all() as $notice) {
        foreach ($expected[$notice->event_code] as $text) {
            expect($notice->message)->toContain($text);
        }
        expect($notice->message)->toContain('29/10/2026 20:00', 'Bolivia')->not->toContain('1234', '77712345', 'C327-CI-PRIVADO', 'Cliente privado combinado', '000000000100001', 'NUMERO-PRIVADO', 'TITULAR-PRIVADO', 'REFERENCIA-PRIVADA', 'NOTA-PRIVADA');
        expect(mb_strlen($notice->message))->toBeLessThanOrEqual(4096);
        expect(DB::table('telegram_dispatches')->where('id', $notice->id)->value('message'))->not->toContain($notice->message);
    }
    foreach (DB::table('jobs')->get() as $job) {
        foreach ($f['bots'] as $bot) {
            expect($job->payload)->not->toContain($bot->token);
        }expect($job->payload)->not->toContain('Samsung', 'Total cobrado', 'Cliente préstamo simulado');
    }
    $seen = [];
    fourBotFake($f, function ($request) use ($pairs, &$seen) {
        $pair = fourBotPair($pairs, $request);
        $seen[] = fourBotPairKey($pair);
        $notice = TelegramDispatch::where('event_code', $pair['code'])->sole();
        $d = $notice->deliveries()->where('status', 'PROCESANDO')->firstOrFail();
        (new SendTelegramDelivery($d->id))->handle(app(ProcessTelegramDeliveryAction::class));

        return fourBotSuccess($pair);
    });
    expect(fourBotDrain(true))->toBe(15);
    fourBotReplay($replays);
    expect($seen)->toHaveCount(15);
    expect(count(array_unique($seen)))->toBe(15);
    expect(TelegramDelivery::where('status', 'ENVIADA')->count())->toBe(15);
    expect(TelegramDeliveryAttempt::count())->toBe(15);
    expect(DB::table('jobs')->count())->toBe(0);
    expect(fourBotBusinessState())->toBe($state);
    Http::assertSentCount(15);
});

it('allows four distinct bots to share one explicitly configured group without conflating their facts', function () {
    $f = fourBotSetup(true, true);
    $replays = fourBotBusinessFlow($f);
    $pairs = fourBotPairs($f);
    $seen = [];
    fourBotFake($f, function ($request) use ($pairs, &$seen) {
        $pair = fourBotPair($pairs, $request);
        $seen[] = $pair['module'];
        expect($pair['chat'])->toBe('-1009000');

        return fourBotSuccess($pair);
    });
    expect(fourBotDrain(true))->toBe(12);
    fourBotReplay($replays);
    expect(count(array_unique($seen)))->toBe(4);
    expect(TelegramDelivery::count())->toBe(12);
    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertSentCount(12);
});

it('retries only the four failed primary deliveries and respects a longer rate-limit delay', function () {
    $f = fourBotSetup();
    $replays = fourBotBusinessFlow($f);
    $state = fourBotBusinessState();
    $pairs = fourBotPairs($f);
    $calls = [];
    fourBotFake($f, function ($request) use ($pairs, &$calls) {
        $p = fourBotPair($pairs, $request);
        $key = fourBotPairKey($p);
        $calls[$key] = ($calls[$key] ?? 0) + 1;
        if (fourBotIsRetryTarget($p) && $calls[$key] === 1) {
            return Http::response(['ok' => false, 'error_code' => $p['module'] === 'PRESTAMOS' ? 429 : 500, 'parameters' => ['retry_after' => $p['module'] === 'PRESTAMOS' ? 120 : 60]], $p['module'] === 'PRESTAMOS' ? 429 : 500);
        }

        return fourBotSuccess($p);
    });
    expect(fourBotDrain(true))->toBe(15);
    expect(TelegramDelivery::where('status', 'REINTENTO_PENDIENTE')->count())->toBe(4);
    expect(TelegramDelivery::where('status', 'ENVIADA')->count())->toBe(11);
    test()->travel(59)->seconds();
    expect(fourBotDrain())->toBe(0);
    test()->travel(1)->seconds();
    expect(fourBotDrain(true))->toBe(3);
    test()->travel(60)->seconds();
    expect(fourBotDrain(true))->toBe(1);
    fourBotReplay($replays);
    expect(TelegramDelivery::where('status', 'ENVIADA')->count())->toBe(15);
    expect(TelegramDeliveryAttempt::count())->toBe(19);
    expect(fourBotBusinessState())->toBe($state);
    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertSentCount(19);
});

it('limits all four bots to three automatic attempts and records a single explicit manual retry per failed delivery', function () {
    $f = fourBotSetup();
    $replays = fourBotBusinessFlow($f);
    $state = fourBotBusinessState();
    $pairs = fourBotPairs($f);
    $calls = [];
    fourBotFake($f, function ($request) use ($pairs, &$calls) {
        $p = fourBotPair($pairs, $request);
        $key = fourBotPairKey($p);
        $calls[$key] = ($calls[$key] ?? 0) + 1;
        if (fourBotIsRetryTarget($p) && $calls[$key] <= 3) {
            return Http::response(['ok' => false, 'error_code' => 500], 500);
        }

        return fourBotSuccess($p);
    });
    fourBotDrain(true);
    test()->travel(60)->seconds();
    expect(fourBotDrain(true))->toBe(4);
    test()->travel(60)->seconds();
    expect(fourBotDrain(true))->toBe(4);
    expect(TelegramDelivery::where('status', 'ERROR_DEFINITIVO')->count())->toBe(4);
    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertSentCount(23);
    foreach (TelegramDelivery::where('status', 'ERROR_DEFINITIVO')->get() as $d) {
        $data = ['delivery_id' => $d->id, 'expected_attempt_count' => 3, 'reason' => 'Destino revisado en simulación', 'confirm_uncertain' => false, 'request_key' => (string) Str::uuid()];
        $a = app(RetryTelegramDeliveryAction::class);
        $a->execute($f['admin'], $data);
        $a->execute($f['admin'], $data);
    }
    expect(fourBotDrain(true))->toBe(4);
    fourBotReplay($replays);
    expect(TelegramDelivery::where('status', 'ENVIADA')->count())->toBe(15);
    expect(TelegramDelivery::where('automatic_attempts', 3)->where('attempt_count', 4)->count())->toBe(4);
    expect(TelegramDeliveryAttempt::where('kind', 'MANUAL')->count())->toBe(4);
    expect(TelegramConfigurationChange::where('kind', 'DELIVERY_RETRY')->count())->toBe(4);
    expect(fourBotBusinessState())->toBe($state);
    Http::assertSentCount(27);
});

it('holds ambiguous replies for all four bots without automatic resend or unacknowledged manual retry', function () {
    $f = fourBotSetup();
    $replays = fourBotBusinessFlow($f);
    $state = fourBotBusinessState();
    $pairs = fourBotPairs($f);
    fourBotFake($f, function ($request) use ($pairs) {
        $p = fourBotPair($pairs, $request);

        return fourBotIsRetryTarget($p) ? Http::response(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => -999]]]) : fourBotSuccess($p);
    });
    fourBotDrain(true);
    test()->travel(300)->seconds();
    expect(app(TelegramDeliveryPublisher::class)->due())->toBe(0);
    expect(fourBotDrain())->toBe(0);
    expect(TelegramDelivery::where('status', 'INCIERTA')->count())->toBe(4);
    foreach (TelegramDelivery::where('status', 'INCIERTA')->get() as $d) {
        $data = ['delivery_id' => $d->id, 'expected_attempt_count' => 1, 'reason' => 'Revisión simulada', 'confirm_uncertain' => false, 'request_key' => (string) Str::uuid()];
        expect(fn () => app(RetryTelegramDeliveryAction::class)->execute($f['admin'], $data))->toThrow(ValidationException::class);
        config(['telegram.sending_enabled' => false]);
        $data['confirm_uncertain'] = true;
        expect(fn () => app(RetryTelegramDeliveryAction::class)->execute($f['admin'], $data))->toThrow(ValidationException::class);
        config(['telegram.sending_enabled' => true]);
    }
    fourBotReplay($replays);
    expect(fourBotBusinessState())->toBe($state);
    expect(TelegramDeliveryAttempt::count())->toBe(15);
    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertSentCount(15);
});

it('cancels changed configuration independently without redirecting old messages to new chats', function () {
    $f = fourBotSetup();
    $replays = fourBotBusinessFlow($f);
    $state = fourBotBusinessState();
    $pairs = fourBotPairs($f);
    $f['primaries']['CAJAS']->update(['chat_id' => '-1006666', 'revision' => 2]);
    $f['bots']['PRESTAMOS']->update(['revision' => 2]);
    $f['events']['TJ_COMPRA']->update(['prepared' => false, 'revision' => 2]);
    $f['primaries']['ACCESORIOS']->update(['responsible_id' => $f['cashier']->id]);
    fourBotFake($f, fn ($request) => fourBotSuccess(fourBotPair($pairs, $request)));
    expect(fourBotDrain(true))->toBe(15);
    expect(TelegramDelivery::where('status', 'CANCELADA')->count())->toBe(10);
    expect(TelegramDelivery::where('status', 'ENVIADA')->count())->toBe(5);
    expect(TelegramDeliveryAttempt::count())->toBe(5);
    fourBotReplay($replays);
    expect(fourBotBusinessState())->toBe($state);
    Http::assertSentCount(5);
    Http::assertNotSent(fn ($r) => (string) ($r->isMultipart() ? collect($r->data())->pluck('contents', 'name')['chat_id'] : $r['chat_id']) === '-1006666');
});

it('never auto-assigns an optional manager and cancels only its explicitly assigned deliveries after role revocation', function () {
    $f = fourBotSetup();
    fourBotBusinessFlow($f);
    $pairs = fourBotPairs($f);
    $f['manager']->syncRoles('cajera');
    fourBotFake($f, function ($request) use ($pairs) {
        $p = fourBotPair($pairs, $request);
        expect($p['primary'])->toBeTrue();

        return fourBotSuccess($p);
    });
    fourBotDrain(true);
    expect(TelegramDelivery::where('status', 'CANCELADA')->count())->toBe(3);
    expect(TelegramDelivery::where('status', 'ENVIADA')->count())->toBe(12);
    Http::assertSentCount(12);
});

it('omits all four modules without a verified connection even in an enabled simulated configuration', function () {
    $f = fourBotSetup();
    TelegramConfigurationChange::query()->delete();
    fourBotBusinessFlow($f);
    expect(TelegramDispatch::where('status', 'OMITIDA')->where('reason', 'NO_AUTHORIZED_DESTINATION')->count())->toBe(12);
    expect(TelegramDelivery::count())->toBe(0);
    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rolls back the combined financial flow and queue intentions if the outer transaction aborts', function () {
    $f = fourBotSetup();
    $state = fourBotBusinessState();
    expect(fn () => DB::transaction(function () use ($f) {
        fourBotBusinessFlow($f);
        throw new RuntimeException('simulated outer interruption');
    }))->toThrow(RuntimeException::class);
    expect(fourBotBusinessState())->toBe($state);
    expect(TelegramDispatch::count())->toBe(0);
    expect(TelegramDelivery::count())->toBe(0);
    expect(DB::table('jobs')->count())->toBe(0);
    fourBotBusinessFlow($f);
    expect(TelegramDispatch::count())->toBe(12);
    expect(DB::table('jobs')->count())->toBe(15);
    Http::assertNothingSent();
});

it('rechecks the global pause for queued jobs of all four bots and does not resurrect omitted deliveries', function () {
    $f = fourBotSetup();
    $replays = fourBotBusinessFlow($f);
    $state = fourBotBusinessState();
    config(['telegram.sending_enabled' => false]);
    expect(fourBotDrain(true))->toBe(15);
    expect(TelegramDelivery::where('status', 'OMITIDA')->count())->toBe(15);
    expect(TelegramDeliveryAttempt::count())->toBe(0);
    config(['telegram.sending_enabled' => true]);
    fourBotReplay($replays);
    expect(app(TelegramDeliveryPublisher::class)->due())->toBe(0);
    expect(fourBotBusinessState())->toBe($state);
    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertNothingSent();
});
