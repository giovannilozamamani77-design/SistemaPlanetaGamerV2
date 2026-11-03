<?php

use App\Domain\Accesorios\Actions\CreateAccessoryProductAction;
use App\Domain\Accesorios\Actions\ReceiveAccessoryStockAction;
use App\Domain\Accesorios\Actions\RegisterAccessorySaleAction;
use App\Domain\Accesorios\Actions\RegisterPhoneUnitAction;
use App\Domain\Accesorios\Actions\ReservePhoneAction;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Accesorios\Models\AccessorySaleLine;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Accesorios\Models\PhoneImei;
use App\Domain\Accesorios\Models\PhoneReservation;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Models\PhoneWarranty;
use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Domain\Comisiones\Actions\RegisterCommissionPaymentAction;
use App\Domain\Comisiones\Actions\VoidCommissionPaymentAction;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Comisiones\Models\CommissionPayment;
use App\Domain\Comisiones\Models\CommissionPaymentReversal;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Domain\Notificaciones\Actions\ProcessTelegramDeliveryAction;
use App\Domain\Notificaciones\Actions\RecordAccessoryTelegramNoticeAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Queries\PanelInboxQuery;
use App\Domain\Prestamos\Models\Borrower;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Accesorios\AccessoryInventoryPermissionsSeeder;
use Database\Seeders\Accesorios\AccessorySalesPermissionsSeeder;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
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
function tgAccessoryFixture(bool $enabled = true, int $quantity = 3, string $model = 'Galaxy A'): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-29 08:00', 'America/La_Paz'));
    config(['telegram.sending_enabled' => $enabled]);
    Http::preventStrayRequests();
    Queue::fake();
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    $admin = User::factory()->create(['name' => 'Admin prueba']);
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['name' => 'Lili prueba']);
    $cashier->assignRole('cajera');
    $other = User::factory()->create(['name' => 'Ana prueba']);
    $other->assignRole('cajera');
    app(RegisterPhoneUnitAction::class)->execute($admin, ['brand' => 'Samsung', 'model' => $model, 'ram_gb' => 8, 'storage_gb' => 256, 'condition' => 'USED', 'color' => 'Negro', 'quantity' => $quantity, 'physical_state' => 'Usado de prueba', 'included_accessories' => 'Cargador', 'purchase_cost' => '800', 'expenses' => '0', 'price_mode' => 'MANUAL', 'sale_price' => '950', 'expected_pricing_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $accessory = app(CreateAccessoryProductAction::class)->execute($admin, ['code' => 'PRUEBA-CABLE', 'name' => 'Cable de prueba', 'sale_price' => '20', 'request_key' => (string) Str::uuid()]);
    app(ReceiveAccessoryStockAction::class)->execute($admin, ['product_id' => $accessory->id, 'kind' => 'INITIAL', 'quantity' => 50, 'unit_cost' => '10', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $bot = TelegramBotSetting::create(['module' => 'ACCESORIOS', 'name' => 'Bot ficticio', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', 'test'), 'is_enabled' => $enabled, 'revision' => 1, 'updated_by' => $admin->id]);
    $dest = TelegramDestination::create(['bot_id' => $bot->id, 'responsible_id' => $admin->id, 'name' => 'Admin ficticio', 'chat_id' => '-100123', 'kind' => 'GROUP', 'is_enabled' => $enabled, 'revision' => 1, 'updated_by' => $admin->id]);
    foreach (['AC_VENTA_TELEFONO', 'AC_COMISION_PAGO', 'AC_COMISION_ANULACION'] as $code) {
        $e = TelegramEventSetting::create(['bot_id' => $bot->id, 'event_code' => $code, 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $admin->id]);
        $e->destinations()->attach($dest->id, ['destination_revision' => 1]);
    }
    foreach (['BOT_CHECK' => $bot->id, 'DESTINATION_CHECK' => $dest->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $admin->id, 'actor_name' => $admin->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['after' => ['revision' => 1], 'bot_revision' => 1, 'status' => 'CONNECTED'], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }

    return [$admin, $cashier, $other, PhoneUnit::orderBy('id')->get(), $accessory->fresh()];
}
function tgAccessorySaleData(User $actor, $units, ?AccessoryProduct $accessory = null, string $discount = '0'): array
{
    $key = (string) Str::uuid();
    $phones = [];
    foreach ($units as $unit) {
        $reservation = app(ReservePhoneAction::class)->execute($actor, $unit->id, $key, 5);
        $phones[] = ['unit_id' => $unit->id, 'reservation_id' => $reservation->id, 'expected_price_cents' => $unit->sale_price_cents, 'discount' => $discount, 'imei1' => str_pad((string) (100000 + $unit->id), 15, '0', STR_PAD_LEFT), 'imei2' => str_pad((string) (200000 + $unit->id), 15, '0', STR_PAD_LEFT)];
    }

    return ['lines' => $accessory ? [['product_id' => $accessory->id, 'quantity' => 2, 'expected_revision' => $accessory->revision, 'expected_price_cents' => $accessory->sale_price_cents]] : [], 'phone_lines' => $phones, 'customer' => ['ci' => 'PRIVADO-CI', 'name' => 'Cliente privado', 'phone' => '77712345', 'email' => 'privado@example.test', 'email2' => null], 'warranty_accepted' => true, 'payment_method' => 'CASH', 'cash_received' => true, 'request_key' => $key];
}
function tgAccessoryPay(User $cashier, string $amount = '20', int $earned = 3000, int $paid = 0, int $reversals = 0): array
{
    return ['user_id' => $cashier->id, 'amount' => $amount, 'expected_earned_cents' => $earned, 'expected_paid_cents' => $paid, 'expected_reversal_count' => $reversals, 'delivered' => true, 'reference' => 'REFERENCIA-PRIVADA', 'note' => 'NOTA-PRIVADA', 'request_key' => (string) Str::uuid()];
}
function tgAccessoryVoid(CommissionPayment $payment): array
{
    return ['payment_id' => $payment->id, 'confirmed' => true, 'reason' => 'Registro incorrecto de prueba', 'request_key' => (string) Str::uuid()];
}
function tgAccessoryOperation(string $kind, User $admin, User $cashier, $units): Closure
{
    $data = tgAccessorySaleData($cashier, $units->take(1));
    if ($kind === 'SALE') {
        return fn () => app(RegisterAccessorySaleAction::class)->execute($cashier, $data);
    }
    app(RegisterAccessorySaleAction::class)->execute($cashier, $data);
    $data = tgAccessoryPay($cashier);
    if ($kind === 'PAYMENT') {
        return fn () => app(RegisterCommissionPaymentAction::class)->execute($admin, $data);
    }
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, $data);
    $data = tgAccessoryVoid($payment);

    return fn () => app(VoidCommissionPaymentAction::class)->execute($admin, $data);
}
function tgAccessoryState(): array
{
    return [CommercialFund::first()?->only(['balance_cents', 'revision']), PhoneUnit::orderBy('id')->pluck('status')->all(), PhoneReservation::orderBy('id')->pluck('status')->all(), PhoneImei::count(), AccessorySale::count(), AccessorySaleLine::count(), PhoneWarranty::count(), Borrower::count(), AccessoryProduct::sum('quantity'), CommissionEntry::count(), CommissionEntry::sum('amount_cents'), CommissionPayment::count(), CommissionPaymentReversal::count(), CommercialMovement::count()];
}

it('omits phone sale payment and void notices during pause without replay resurrection', function () {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture(false);
    $data = tgAccessorySaleData($cashier, $units->take(1));
    $sale = app(RegisterAccessorySaleAction::class)->execute($cashier, $data);
    $pay = tgAccessoryPay($cashier);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, $pay);
    $void = tgAccessoryVoid($payment);
    $reversal = app(VoidCommissionPaymentAction::class)->execute($admin, $void);
    $state = tgAccessoryState();
    config(['telegram.sending_enabled' => true]);
    expect(app(RegisterAccessorySaleAction::class)->execute($cashier, $data)->id)->toBe($sale->id);
    expect(app(RegisterCommissionPaymentAction::class)->execute($admin, $pay)->id)->toBe($payment->id);
    expect(app(VoidCommissionPaymentAction::class)->execute($admin, $void)->id)->toBe($reversal->id);
    expect(TelegramDispatch::count())->toBe(3);
    expect(TelegramDispatch::where('status', 'OMITIDA')->whereNull('message')->count())->toBe(3);
    expect(TelegramDelivery::count())->toBe(0);
    expect(TelegramDispatch::whereNotNull('media')->count())->toBe(0);
    expect(tgAccessoryState())->toBe($state);
    expect(app(CommissionPaymentQuery::class)->balance($admin, $cashier->id)['pending_cents'])->toBe(3000);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('announces a phone or mixed sale once with saved totals and discounts but no private customer or IMEI', function (bool $mixed, string $discount, string $total, string $earned) {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    $data = tgAccessorySaleData($cashier, $units->take(1), $mixed ? $accessory : null, $discount);
    $sale = app(RegisterAccessorySaleAction::class)->execute($cashier, $data);
    expect(app(RegisterAccessorySaleAction::class)->execute($cashier, $data)->id)->toBe($sale->id);
    $notice = TelegramDispatch::where('event_code', 'AC_VENTA_TELEFONO')->sole();
    expect($notice->module)->toBe('ACCESORIOS');
    expect($notice->message)->toContain('Venta de teléfonos registrada', 'Venta #'.$sale->id, 'Samsung Galaxy A', 'Teléfonos: 1', 'Total cobrado: Bs '.$total, 'Descuento total: Bs '.$discount, 'Lili prueba', '29/10/2026 08:00', 'Bolivia')->not->toContain('Cliente privado', 'PRIVADO-CI', '77712345', 'privado@example.test', $data['phone_lines'][0]['imei1'], $data['phone_lines'][0]['imei2']);
    if ($mixed) {
        expect($notice->message)->toContain('Cable de prueba', 'Accesorios: 2');
    }
    expect(CommissionEntry::sum('amount_cents'))->toBe((int) $earned);
    expect(TelegramDispatch::count())->toBe(1);
    expect(CommercialMovement::count())->toBe(1);
    expect(PhoneWarranty::count())->toBe(1);
    expect(PhoneUnit::where('status', 'AVAILABLE')->count())->toBe(2);
    $text = $notice->message;
    $cashier->update(['name' => 'Nombre nuevo']);
    $units->first()->update(['sale_price_cents' => 96000]);
    app(RegisterAccessorySaleAction::class)->execute($cashier, $data);
    expect($notice->fresh()->message)->toBe($text);
    Http::assertNothingSent();
})->with([[false, '0', '950', '3000'], [false, '30', '920', '2400'], [true, '30', '960', '2600']]);

it('sends one notice for multiple phones without repeating cash warranties or commissions', function () {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    $data = tgAccessorySaleData($cashier, $units->take(2));
    app(RegisterAccessorySaleAction::class)->execute($cashier, $data);
    app(RegisterAccessorySaleAction::class)->execute($cashier, $data);
    expect(TelegramDispatch::sole()->message)->toContain('Teléfonos: 2', 'Total cobrado: Bs 1900');
    expect(AccessorySale::count())->toBe(1);
    expect(PhoneWarranty::count())->toBe(2);
    expect(CommissionEntry::count())->toBe(2);
    expect(CommercialMovement::count())->toBe(1);
    expect(CommercialFund::sole()->balance_cents)->toBe(190000);
    expect(PhoneUnit::where('status', 'AVAILABLE')->count())->toBe(1);
    Http::assertNothingSent();
});

it('does not notify routine accessories-only sales previews or reprinting receipts', function () {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    $data = ['lines' => [['product_id' => $accessory->id, 'quantity' => 2, 'expected_revision' => $accessory->revision, 'expected_price_cents' => $accessory->sale_price_cents]], 'payment_method' => 'CASH', 'cash_received' => true, 'request_key' => (string) Str::uuid()];
    app(AccessorySaleQuery::class)->preview($cashier, $data);
    expect(TelegramDispatch::count())->toBe(0);
    $sale = app(RegisterAccessorySaleAction::class)->execute($cashier, $data);
    app(AccessorySaleQuery::class)->receipt($cashier, $sale->id);
    app(AccessorySaleQuery::class)->receipt($cashier, $sale->id);
    expect(TelegramDispatch::count())->toBe(0);
    expect(CommissionEntry::sum('amount_cents'))->toBe(200);
    expect(CommercialFund::sole()->balance_cents)->toBe(4000);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('freezes the external commission payment and its remaining balance without withdrawing system funds', function (string $amount, int $paid, string $pending) {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    app(RegisterAccessorySaleAction::class)->execute($cashier, tgAccessorySaleData($cashier, $units->take(1)));
    $fund = CommercialFund::sole()->getRawOriginal();
    $data = tgAccessoryPay($cashier, $amount);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, $data);
    app(RegisterCommissionPaymentAction::class)->execute($admin, $data);
    $notice = TelegramDispatch::where('event_code', 'AC_COMISION_PAGO')->sole();
    expect($notice->module)->toBe('ACCESORIOS');
    expect($notice->message)->toContain('Pago de comisiones registrado', 'Pago #'.$payment->id, 'Lili prueba', 'Admin prueba', 'Importe pagado: Bs '.$amount, 'Pendiente tras este pago: Bs '.$pending, 'Entrega externa de Administración')->not->toContain('REFERENCIA-PRIVADA', 'NOTA-PRIVADA');
    expect(CommercialFund::sole()->getRawOriginal())->toBe($fund);
    expect(CommercialMovement::count())->toBe(1);
    expect(DB::table('card_inventory_movements')->count())->toBe(0);
    expect(DB::table('loan_movements')->count())->toBe(0);
    expect(CommissionPayment::count())->toBe(1);
    expect(TelegramDispatch::count())->toBe(2);
    expect(app(CommissionPaymentQuery::class)->balance($admin, $cashier->id)['paid_cents'])->toBe($paid);
    Http::assertNothingSent();
})->with([['20', 2000, '10'], ['30', 3000, '0'], ['0.01', 1, '29.99']]);

it('announces an audited reversal separately and keeps the old payment message and private cashier notices', function () {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    app(RegisterAccessorySaleAction::class)->execute($cashier, tgAccessorySaleData($cashier, $units->take(1)));
    $pay = tgAccessoryPay($cashier);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, $pay);
    $text = TelegramDispatch::where('event_code', 'AC_COMISION_PAGO')->sole()->message;
    $fund = CommercialFund::sole()->getRawOriginal();
    $admin2 = User::factory()->create(['name' => 'Otra administración']);
    $admin2->assignRole('administrador');
    $data = tgAccessoryVoid($payment);
    $reversal = app(VoidCommissionPaymentAction::class)->execute($admin2, $data);
    app(VoidCommissionPaymentAction::class)->execute($admin2, $data);
    app(RegisterCommissionPaymentAction::class)->execute($admin, $pay);
    $notice = TelegramDispatch::where('event_code', 'AC_COMISION_ANULACION')->sole();
    expect($notice->message)->toContain('Pago de comisiones anulado', 'Pago #'.$payment->id, 'Anulación #'.$reversal->id, 'Lili prueba', 'Otra administración', 'Importe restituido: Bs 20', 'Pendiente tras esta anulación: Bs 30', 'Registro incorrecto de prueba');
    expect(TelegramDispatch::where('event_code', 'AC_COMISION_PAGO')->sole()->message)->toBe($text);
    expect(CommercialFund::sole()->getRawOriginal())->toBe($fund);
    expect(CommissionPayment::count())->toBe(1);
    expect(CommissionPaymentReversal::count())->toBe(1);
    expect(CommissionEntry::sum('amount_cents'))->toBe(3000);
    expect(TelegramDispatch::count())->toBe(3);
    $q = app(PanelInboxQuery::class);
    expect($q->execute($cashier)->whereKey('commission-payment:'.$payment->id)->exists())->toBeTrue();
    expect($q->execute($cashier)->whereKey('commission-payment-void:'.$reversal->id)->exists())->toBeTrue();
    expect($q->execute($cashier)->count())->toBe(2);
    expect($q->execute($other)->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps different partial payments and a repayment after reversal distinct without reactivating old facts', function () {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    app(RegisterAccessorySaleAction::class)->execute($cashier, tgAccessorySaleData($cashier, $units->take(1)));
    $data = tgAccessoryPay($cashier);
    $first = app(RegisterCommissionPaymentAction::class)->execute($admin, $data);
    $text = TelegramDispatch::where('event_code', 'AC_COMISION_PAGO')->sole()->message;
    app(RegisterCommissionPaymentAction::class)->execute($admin, tgAccessoryPay($cashier, '10', 3000, 2000));
    app(VoidCommissionPaymentAction::class)->execute($admin, tgAccessoryVoid($first));
    app(RegisterCommissionPaymentAction::class)->execute($admin, tgAccessoryPay($cashier, '20', 3000, 1000, 1));
    app(RegisterCommissionPaymentAction::class)->execute($admin, $data);
    expect(TelegramDispatch::where('event_code', 'AC_COMISION_PAGO')->count())->toBe(3);
    expect(TelegramDispatch::where('source_reference', 'payment:'.$first->id)->sole()->message)->toBe($text);
    expect(TelegramDispatch::count())->toBe(5);
    expect(app(CommissionPaymentQuery::class)->balance($admin, $cashier->id)['pending_cents'])->toBe(0);
    expect(CommercialFund::sole()->balance_cents)->toBe(95000);
    Http::assertNothingSent();
});

it('does not notify invalid sales or commission payments and reversals by an unauthorized cashier', function () {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    $data = tgAccessorySaleData($cashier, $units->take(1));
    $invalid = $data;
    $invalid['phone_lines'][0]['discount'] = '31';
    expect(fn () => app(RegisterAccessorySaleAction::class)->execute($cashier, $invalid))->toThrow(ValidationException::class);
    expect(TelegramDispatch::count())->toBe(0);
    app(RegisterAccessorySaleAction::class)->execute($cashier, $data);
    expect(fn () => app(RegisterCommissionPaymentAction::class)->execute($cashier, tgAccessoryPay($cashier)))->toThrow(AuthorizationException::class);
    expect(fn () => app(RegisterCommissionPaymentAction::class)->execute($admin, tgAccessoryPay($cashier, '31')))->toThrow(ValidationException::class);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, tgAccessoryPay($cashier));
    expect(fn () => app(VoidCommissionPaymentAction::class)->execute($cashier, tgAccessoryVoid($payment)))->toThrow(AuthorizationException::class);
    $bad = tgAccessoryVoid($payment);
    $bad['reason'] = '';
    expect(fn () => app(VoidCommissionPaymentAction::class)->execute($admin, $bad))->toThrow(ValidationException::class);
    expect(TelegramDispatch::count())->toBe(2);
    expect(app(CommissionPaymentQuery::class)->balance($admin, $cashier->id)['pending_cents'])->toBe(1000);
    Http::assertNothingSent();
});

it('rolls back source and notification on outer transaction interruption then retries once', function (string $kind) {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    $run = tgAccessoryOperation($kind, $admin, $cashier, $units);
    $state = tgAccessoryState();
    $count = TelegramDispatch::count();
    expect(fn () => DB::transaction(function () use ($run) {
        $run();
        throw new RuntimeException('interruption');
    }))->toThrow(RuntimeException::class);
    expect(tgAccessoryState())->toBe($state);
    expect(TelegramDispatch::count())->toBe($count);
    $run();
    $run();
    expect(TelegramDispatch::count())->toBe($count + 1);
    Http::assertNothingSent();
})->with(['SALE', 'PAYMENT', 'VOID']);

it('rolls back money stock warranties earnings and payment balances when saving the outbox fails', function (string $kind, string $code) {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    $run = tgAccessoryOperation($kind, $admin, $cashier, $units);
    $state = tgAccessoryState();
    $count = TelegramDispatch::count();
    DB::statement("CREATE TRIGGER fail_accessory_notice BEFORE INSERT ON telegram_dispatches WHEN NEW.event_code = '$code' BEGIN SELECT RAISE(ABORT,'outbox interruption'); END");
    expect($run)->toThrow(QueryException::class);
    expect(tgAccessoryState())->toBe($state);
    expect(TelegramDispatch::count())->toBe($count);
    DB::statement('DROP TRIGGER fail_accessory_notice');
    $run();
    $run();
    expect(TelegramDispatch::count())->toBe($count + 1);
    Http::assertNothingSent();
})->with([['SALE', 'AC_VENTA_TELEFONO'], ['PAYMENT', 'AC_COMISION_PAGO'], ['VOID', 'AC_COMISION_ANULACION']]);

it('retries a simulated accessory bot message without repeating a sale payment or reversal', function (string $code) {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    app(RegisterAccessorySaleAction::class)->execute($cashier, tgAccessorySaleData($cashier, $units->take(1)));
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, tgAccessoryPay($cashier));
    app(VoidCommissionPaymentAction::class)->execute($admin, tgAccessoryVoid($payment));
    $state = tgAccessoryState();
    $notice = TelegramDispatch::where('event_code', $code)->sole();
    $delivery = $notice->deliveries()->sole();
    Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false, 'error_code' => 500], 500)->push(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => -100123]]])]);
    $process = app(ProcessTelegramDeliveryAction::class);
    $process->execute($delivery->id);
    test()->travel(60)->seconds();
    $process->execute($delivery->id);
    $process->execute($delivery->id);
    expect($delivery->fresh()->status)->toBe('ENVIADA');
    expect(tgAccessoryState())->toBe($state);
    expect(TelegramDispatch::count())->toBe(3);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto') && collect($request->data())->firstWhere('name', 'caption')['contents'] === $notice->media['caption']);
})->with(['AC_VENTA_TELEFONO', 'AC_COMISION_PAGO', 'AC_COMISION_ANULACION']);

it('does not backfill old sale payment or void notifications by replaying their confirmed sources', function () {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture(false);
    $sale = tgAccessorySaleData($cashier, $units->take(1));
    app(RegisterAccessorySaleAction::class)->execute($cashier, $sale);
    $pay = tgAccessoryPay($cashier);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, $pay);
    $void = tgAccessoryVoid($payment);
    app(VoidCommissionPaymentAction::class)->execute($admin, $void);
    $state = tgAccessoryState();
    TelegramDispatch::query()->delete();
    config(['telegram.sending_enabled' => true]);
    app(RegisterAccessorySaleAction::class)->execute($cashier, $sale);
    app(RegisterCommissionPaymentAction::class)->execute($admin, $pay);
    app(VoidCommissionPaymentAction::class)->execute($admin, $void);
    expect(TelegramDispatch::count())->toBe(0);
    expect(tgAccessoryState())->toBe($state);
    Http::assertNothingSent();
});

it('preserves each confirmed commercial fact if publishing its Telegram job fails', function (string $kind) {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    $run = tgAccessoryOperation($kind, $admin, $cashier, $units);
    Queue::partialMock()->shouldReceive('connection')->with('telegram')->andThrow(new RuntimeException('queue unavailable'));
    $run();
    $run();
    $notice = TelegramDispatch::latest('id')->firstOrFail();
    expect($notice->deliveries()->sole()->status)->toBe('PENDIENTE');
    expect($notice->deliveries()->sole()->queued_at)->toBeNull();
    expect(AccessorySale::count())->toBe(1);
    expect(CommercialFund::sole()->balance_cents)->toBe(95000);
    expect(CommercialMovement::count())->toBe(1);
    expect(CommissionPayment::count())->toBe($kind === 'SALE' ? 0 : 1);
    expect(CommissionPaymentReversal::count())->toBe($kind === 'VOID' ? 1 : 0);
    Http::assertNothingSent();
})->with(['SALE', 'PAYMENT', 'VOID']);

it('keeps phone models visible and all saved totals in a large mixed sale within Telegram limits', function () {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture(true, 1);
    $data = tgAccessorySaleData($cashier, $units);
    for ($i = 0; $i < 24; $i++) {
        $p = app(CreateAccessoryProductAction::class)->execute($admin, ['code' => 'LARGO-'.$i, 'name' => 'Accesorio '.str_repeat('largo ', 15), 'sale_price' => '20', 'request_key' => (string) Str::uuid()]);
        app(ReceiveAccessoryStockAction::class)->execute($admin, ['product_id' => $p->id, 'kind' => 'INITIAL', 'quantity' => 1, 'unit_cost' => '10', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
        $p->refresh();
        $data['lines'][] = ['product_id' => $p->id, 'quantity' => 1, 'expected_revision' => $p->revision, 'expected_price_cents' => $p->sale_price_cents];
    }
    $sale = app(RegisterAccessorySaleAction::class)->execute($cashier, $data);
    $message = TelegramDispatch::sole()->message;
    expect($message)->toContain('Samsung Galaxy A', 'Teléfonos: 1', 'Accesorios: 24', 'Total cobrado: Bs 1430', 'Descuento total: Bs 0', '17 líneas más');
    expect(mb_strlen($message))->toBeLessThanOrEqual(4096);
    expect(PhoneWarranty::count())->toBe(1);
    expect(CommissionEntry::count())->toBe(25);
    expect(CommercialMovement::count())->toBe(1);
    expect(CommercialFund::sole()->balance_cents)->toBe(143000);
    expect(TelegramDispatch::count())->toBe(1);
    Http::assertNothingSent();
});

it('bounds free text and removes user links from models actors and reversal reasons', function () {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture(true, 1, 'Galaxy https://example.invalid @todos');
    $cashier->update(['name' => 'Lili https://example.invalid @todos']);
    $data = tgAccessorySaleData($cashier, $units);
    app(RegisterAccessorySaleAction::class)->execute($cashier, $data);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, tgAccessoryPay($cashier));
    $void = tgAccessoryVoid($payment);
    $void['reason'] = "Motivo https://example.invalid @todos\n".str_repeat('Largo ', 35);
    app(VoidCommissionPaymentAction::class)->execute($admin, $void);
    foreach (TelegramDispatch::all() as $notice) {
        expect($notice->message)->toContain('[enlace omitido]')->not->toContain('example.invalid', '@todos', 'Cliente privado', 'PRIVADO-CI', 'REFERENCIA-PRIVADA', 'NOTA-PRIVADA');
        expect(mb_strlen($notice->message))->toBeLessThanOrEqual(4096);
    }
    expect(CommissionPaymentReversal::sole()->reason)->toBe(trim($void['reason']));
    Http::assertNothingSent();
});

it('sends one frozen photo per phone sale commission payment and reversal without repeating finances', function () {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    $saleData = tgAccessorySaleData($cashier, $units->take(1));
    $sale = app(RegisterAccessorySaleAction::class)->execute($cashier, $saleData);
    $payData = tgAccessoryPay($cashier);
    $payment = app(RegisterCommissionPaymentAction::class)->execute($admin, $payData);
    $voidData = tgAccessoryVoid($payment);
    app(VoidCommissionPaymentAction::class)->execute($admin, $voidData);
    $state = tgAccessoryState();
    $photos = TelegramDispatch::orderBy('id')->get()->mapWithKeys(fn ($n) => [$n->id => $n->media])->all();
    foreach (TelegramDispatch::all() as $notice) {
        expect($notice->media['filename'])->toBe('logoReciboPrestamo.png')->and(mb_strlen($notice->media['caption']))->toBeLessThanOrEqual(1024);
        expect($notice->media['caption'])->not->toContain('Cliente privado', 'PRIVADO-CI', '77712345', 'privado@example.test', '000000000100001', '000000000200001', 'REFERENCIA-PRIVADA', 'NOTA-PRIVADA', 'Bs 800');
        expect(DB::table('telegram_dispatches')->where('id', $notice->id)->value('media'))->not->toContain('logoReciboPrestamo', 'Lili prueba');
    }
    expect(TelegramDispatch::where('event_code', 'AC_COMISION_PAGO')->sole()->media['caption'])->toContain('Importe pagado: Bs 20', 'Pendiente tras este pago: Bs 10');
    expect(TelegramDispatch::where('event_code', 'AC_COMISION_ANULACION')->sole()->media['caption'])->toContain('Importe restituido: Bs 20', 'Pendiente tras esta anulación: Bs 30');
    $token = TelegramBotSetting::where('module', 'ACCESORIOS')->sole()->token;
    Http::fake(['https://api.telegram.org/bot'.$token.'/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => -100123]]])]);
    $cashier->update(['name' => 'Nombre posterior']);
    foreach (TelegramDelivery::all() as $delivery) {
        app(ProcessTelegramDeliveryAction::class)->execute($delivery->id);
        app(ProcessTelegramDeliveryAction::class)->execute($delivery->id);
    }
    app(RegisterAccessorySaleAction::class)->execute($cashier, $saleData);
    app(RegisterCommissionPaymentAction::class)->execute($admin, $payData);
    app(VoidCommissionPaymentAction::class)->execute($admin, $voidData);
    expect(TelegramDispatch::count())->toBe(3)->and(TelegramDelivery::where('status', 'ENVIADA')->count())->toBe(3)->and(tgAccessoryState())->toBe($state);
    expect(TelegramDispatch::orderBy('id')->get()->mapWithKeys(fn ($n) => [$n->id => $n->media])->all())->toBe($photos);
    Http::assertSentCount(3);
});

it('shows the saved phone and mixed sale totals in the photo caption', function ($mixed, $total) {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    $data = tgAccessorySaleData($cashier, $units->take(1), $mixed ? $accessory : null, '10');
    app(RegisterAccessorySaleAction::class)->execute($cashier, $data);
    $notice = TelegramDispatch::sole();
    expect($notice->media['caption'])->toContain('Teléfonos: 1', 'Accesorios: '.($mixed ? 2 : 0), 'Descuento total: Bs 10', 'Total cobrado: Bs '.$total);
    Http::assertNothingSent();
})->with([[false, '940'], [true, '980']]);

it('preserves an old text sale notice when its adapter is replayed after the style change', function () {
    [$admin,$cashier,$other,$units,$accessory] = tgAccessoryFixture();
    $sale = app(RegisterAccessorySaleAction::class)->execute($cashier, tgAccessorySaleData($cashier, $units->take(1)));
    $old = TelegramDispatch::sole();
    $old->update(['message' => 'Aviso histórico conservado', 'media' => null]);
    $state = tgAccessoryState();
    DB::transaction(fn () => app(RecordAccessoryTelegramNoticeAction::class)->sale($sale->id));
    expect($old->fresh()->message)->toBe('Aviso histórico conservado')->and($old->fresh()->media)->toBeNull();
    expect(TelegramDispatch::count())->toBe(1)->and(TelegramDelivery::count())->toBe(1)->and(tgAccessoryState())->toBe($state);
    Http::assertNothingSent();
});
