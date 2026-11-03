<?php

use App\Domain\Accesorios\Actions\RecordAccessoryCashDepositAction;
use App\Domain\Accesorios\Actions\VoidAccessoryCashDepositAction;
use App\Domain\Accesorios\Models\AccessoryCashDeposit;
use App\Domain\Accesorios\Models\AccessoryCashDepositReversal;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Notificaciones\Actions\ProcessTelegramDeliveryAction;
use App\Domain\Notificaciones\Actions\RecordAccessoryTelegramNoticeAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Queries\TelegramEventsQuery;
use App\Domain\Tarjetas\Models\CardDepositAccount;
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
function tgDepositFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-30 14:00', 'America/La_Paz'));
    config(['telegram.sending_enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake();
    test()->seed([UsuariosPermissionsSeeder::class, AccessoryInventoryPermissionsSeeder::class, AccessorySalesPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    $admin = User::factory()->create(['name' => 'Admin de prueba']);
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['name' => 'Cajera de prueba']);
    $cashier->assignRole('cajera');
    $fund = CommercialFund::create(['code' => 'GENERAL', 'balance_cents' => 10000, 'revision' => 0]);
    $account = CardDepositAccount::create(['name' => 'Cuenta destino ejemplo', 'bank' => 'Banco de prueba', 'number' => 'CUENTA-SECRETA-123', 'holder' => 'TITULAR-PRIVADO', 'qr_path' => 'QR-PRIVADO.png', 'is_active' => true, 'revision' => 1, 'created_by' => $admin->id, 'updated_by' => $admin->id]);
    $bot = TelegramBotSetting::create(['module' => 'ACCESORIOS', 'name' => 'Bot simulado', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', 'fake'), 'is_enabled' => true, 'revision' => 1, 'updated_by' => $admin->id]);
    $dest = TelegramDestination::create(['bot_id' => $bot->id, 'responsible_id' => $admin->id, 'name' => 'Destino de prueba', 'chat_id' => '-100123', 'kind' => 'GROUP', 'is_enabled' => true, 'revision' => 1, 'updated_by' => $admin->id]);
    foreach (['AC_EFECTIVO_DEPOSITO', 'AC_EFECTIVO_ANULACION'] as $code) {
        $event = TelegramEventSetting::create(['bot_id' => $bot->id, 'event_code' => $code, 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $admin->id]);
        $event->destinations()->attach($dest->id, ['destination_revision' => 1]);
    }
    foreach (['BOT_CHECK' => $bot->id, 'DESTINATION_CHECK' => $dest->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $admin->id, 'actor_name' => $admin->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['after' => ['revision' => 1], 'bot_revision' => 1, 'status' => 'CONNECTED'], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }

    return [$admin, $cashier, $account, $fund, $bot, $dest];
}
function tgDepositData($account, $fund): array
{
    return ['account_id' => $account->id, 'account_revision' => 1, 'expected_revision' => $fund->fresh()->revision, 'amount' => '40', 'operation_reference' => 'OP-TEST', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
}
function tgDepositVoidData($deposit, $fund): array
{
    return ['deposit_id' => $deposit->id, 'expected_revision' => $fund->fresh()->revision, 'reason' => 'Registro equivocado', 'confirmed' => true, 'request_key' => (string) Str::uuid()];
}
function tgDepositOperation($kind, $f): Closure
{
    [$admin,$cashier,$account,$fund] = $f;
    $data = tgDepositData($account, $fund);
    if ($kind === 'DEPOSIT') {
        return fn () => app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data);
    }
    $d = app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data);
    $data = tgDepositVoidData($d, $fund);

    return fn () => app(VoidAccessoryCashDepositAction::class)->execute($admin, $data);
}
function tgDepositState(): array
{
    return [CommercialFund::get()->toArray(), CommercialMovement::get()->toArray(), AccessoryCashDeposit::get()->toArray(), AccessoryCashDepositReversal::get()->toArray()];
}
it('C348 freezes one branded notice per deposit and reversal with historical cash and no private account details', function () {
    [$admin,$cashier,$account,$fund,$bot] = $f = tgDepositFixture();
    $data = tgDepositData($account, $fund);
    $d = app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data);
    $v = tgDepositVoidData($d, $fund);
    $r = app(VoidAccessoryCashDepositAction::class)->execute($admin, $v);
    $notices = TelegramDispatch::orderBy('id')->get();
    expect($notices)->toHaveCount(2)->and(TelegramDelivery::count())->toBe(2);
    expect($notices[0]->event_code)->toBe('AC_EFECTIVO_DEPOSITO')->and($notices[0]->message)->toContain('Cajera de prueba', 'Importe depositado: Bs 40', 'Efectivo disponible posterior: Bs 60', 'OP-TEST', 'Banco de prueba');
    expect($notices[1]->event_code)->toBe('AC_EFECTIVO_ANULACION')->and($notices[1]->message)->toContain('Admin de prueba', 'Depositó: Cajera de prueba', 'Importe restituido: Bs 40', 'Efectivo disponible posterior: Bs 100', 'Registro equivocado', 'Depósito #'.$d->id);
    foreach ($notices as $notice) {
        expect($notice->media['filename'])->toBe('logoReciboPrestamo.png')->and($notice->media['version'])->toBe(2)->and(mb_strlen($notice->media['caption']))->toBeLessThanOrEqual(1024);
        expect($notice->message)->not->toContain('CUENTA-SECRETA-123', 'TITULAR-PRIVADO', 'QR-PRIVADO.png');
        expect($notice->getRawOriginal('message'))->not->toContain('Banco de prueba');
    }
    $account->update(['name' => 'Cuenta renombrada', 'is_active' => false]);
    $cashier->update(['name' => 'Otro nombre']);
    $state = tgDepositState();
    $frozen = $notices->map(fn ($n) => [$n->message, $n->media])->all();
    app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data);
    app(VoidAccessoryCashDepositAction::class)->execute($admin, $v);
    DB::transaction(function () use ($d, $r) {
        app(RecordAccessoryTelegramNoticeAction::class)->deposit($d->id);
        app(RecordAccessoryTelegramNoticeAction::class)->depositReversal($r->id);
    });
    expect(TelegramDispatch::count())->toBe(2)->and(TelegramDispatch::orderBy('id')->get()->map(fn ($n) => [$n->message, $n->media])->all())->toBe($frozen)->and(tgDepositState())->toBe($state);
    Http::assertNothingSent();
});
it('C348 retries a simulated photo without repeating cash or dispatches', function ($kind) {
    $f = tgDepositFixture();
    $run = tgDepositOperation($kind, $f);
    $run();
    $state = tgDepositState();
    $code = $kind === 'DEPOSIT' ? 'AC_EFECTIVO_DEPOSITO' : 'AC_EFECTIVO_ANULACION';
    $n = TelegramDispatch::where('event_code', $code)->sole();
    $delivery = $n->deliveries()->sole();
    Http::fake(['https://api.telegram.org/bot'.$f[4]->token.'/sendPhoto' => Http::sequence()->push(['ok' => false, 'error_code' => 500], 500)->push(['ok' => true, 'result' => ['message_id' => 90, 'chat' => ['id' => -100123]]])]);
    $p = app(ProcessTelegramDeliveryAction::class);
    $p->execute($delivery->id);
    test()->travel(60)->seconds();
    $p->execute($delivery->id);
    $p->execute($delivery->id);
    $run();
    expect($delivery->fresh()->status)->toBe('ENVIADA')->and(tgDepositState())->toBe($state)->and($n->deliveries()->count())->toBe(1);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->hasFile('photo', base64_decode($n->media['content'], true)));
})->with(['DEPOSIT', 'VOID']);
it('C348 rolls back the financial source if saving the outbox fails then retries once', function ($kind) {
    $f = tgDepositFixture();
    $run = tgDepositOperation($kind, $f);
    $state = tgDepositState();
    $count = TelegramDispatch::count();
    $code = $kind === 'DEPOSIT' ? 'AC_EFECTIVO_DEPOSITO' : 'AC_EFECTIVO_ANULACION';
    DB::statement("CREATE TRIGGER fail_deposit_outbox BEFORE INSERT ON telegram_dispatches WHEN NEW.event_code = '$code' BEGIN SELECT RAISE(ABORT,'simulated'); END");
    expect($run)->toThrow(QueryException::class);
    expect(tgDepositState())->toBe($state)->and(TelegramDispatch::count())->toBe($count);
    DB::statement('DROP TRIGGER fail_deposit_outbox');
    $run();
    $run();
    expect(TelegramDispatch::count())->toBe($count + 1);
    Http::assertNothingSent();
})->with(['DEPOSIT', 'VOID']);
it('C348 discards the intention on an interrupted outer transaction', function ($kind) {
    $f = tgDepositFixture();
    $run = tgDepositOperation($kind, $f);
    $state = tgDepositState();
    $count = TelegramDispatch::count();
    expect(fn () => DB::transaction(function () use ($run) {
        $run();
        throw new RuntimeException('interrupted');
    }))->toThrow(RuntimeException::class);
    expect(tgDepositState())->toBe($state)->and(TelegramDispatch::count())->toBe($count);
    $run();
    $run();
    expect(TelegramDispatch::count())->toBe($count + 1);
    Http::assertNothingSent();
})->with(['DEPOSIT', 'VOID']);
it('C348 honors pause event and recipient guards without backfill', function ($guard) {
    [$admin,$cashier,$account,$fund,$bot,$dest] = tgDepositFixture();
    match ($guard) {
        'GLOBAL' => config(['telegram.sending_enabled' => false]),'BOT' => $bot->update(['is_enabled' => false]),'DESTINATION' => $dest->update(['is_enabled' => false]),'EVENT' => TelegramEventSetting::query()->update(['prepared' => false]),'RECIPIENT' => $dest->update(['responsible_id' => $cashier->id])
    };
    $data = tgDepositData($account, $fund);
    $d = app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data);
    $v = tgDepositVoidData($d, $fund);
    app(VoidAccessoryCashDepositAction::class)->execute($admin, $v);
    expect(TelegramDispatch::where('status', 'OMITIDA')->count())->toBe(2)->and(TelegramDispatch::whereNotNull('message')->count())->toBe(0)->and(TelegramDispatch::whereNotNull('media')->count())->toBe(0)->and(TelegramDelivery::count())->toBe(0);
    config(['telegram.sending_enabled' => true]);
    $bot->update(['is_enabled' => true]);
    $dest->update(['is_enabled' => true, 'responsible_id' => $admin->id]);
    TelegramEventSetting::query()->update(['prepared' => true]);
    app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data);
    app(VoidAccessoryCashDepositAction::class)->execute($admin, $v);
    expect(TelegramDelivery::count())->toBe(0)->and($fund->fresh()->balance_cents)->toBe(10000);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['GLOBAL', 'BOT', 'DESTINATION', 'EVENT', 'RECIPIENT']);
it('C348 keeps an uncertain upload from creating a second message or repeating cash', function () {
    $f = tgDepositFixture();
    $run = tgDepositOperation('DEPOSIT', $f);
    $run();
    $state = tgDepositState();
    $delivery = TelegramDelivery::sole();
    Http::fake(['https://api.telegram.org/bot'.$f[4]->token.'/sendPhoto' => Http::failedConnection()]);
    $p = app(ProcessTelegramDeliveryAction::class);
    $p->execute($delivery->id);
    test()->travel(120)->seconds();
    $p->execute($delivery->id);
    $run();
    expect($delivery->fresh()->status)->toBe('INCIERTA')->and(tgDepositState())->toBe($state)->and(TelegramDispatch::count())->toBe(1);
    Http::assertSentCount(1);
});
it('C348 does not announce invalid deposits or cashier reversals', function () {
    [$admin,$cashier,$account,$fund] = tgDepositFixture();
    $data = tgDepositData($account, $fund);
    expect(fn () => app(RecordAccessoryCashDepositAction::class)->execute($cashier, array_replace($data, ['amount' => '101'])))->toThrow(ValidationException::class);
    expect(TelegramDispatch::count())->toBe(0);
    $d = app(RecordAccessoryCashDepositAction::class)->execute($cashier, $data);
    expect(fn () => app(VoidAccessoryCashDepositAction::class)->execute($cashier, tgDepositVoidData($d, $fund)))->toThrow(AuthorizationException::class);
    expect(TelegramDispatch::count())->toBe(1)->and($fund->fresh()->balance_cents)->toBe(6000);
    Http::assertNothingSent();
});
it('C348 exposes two configurable previews with brand images without sending', function () {
    [$admin] = tgDepositFixture();
    $q = app(TelegramEventsQuery::class);
    $events = $q->forModule($admin, 'ACCESORIOS')['events'];
    foreach (['AC_EFECTIVO_DEPOSITO', 'AC_EFECTIVO_ANULACION'] as $code) {
        $p = $q->preview($admin, 'ACCESORIOS', $code);
        expect($p['message'])->toContain('EJEMPLO', 'Bolivia', 'Efectivo disponible posterior')->and($p['media']['filename'])->toBe('logoReciboPrestamo.png')->and($p['template_version'])->toBe(2);
    }
    Http::assertNothingSent();
});

it('C348 does not backfill historical source operations that have no saved intention', function ($kind) {
    $f = tgDepositFixture();
    config(['telegram.sending_enabled' => false]);
    $run = tgDepositOperation($kind, $f);
    $run();
    TelegramDispatch::query()->delete();
    $state = tgDepositState();
    config(['telegram.sending_enabled' => true]);
    $run();
    $run();
    expect(TelegramDispatch::count())->toBe(0)->and(tgDepositState())->toBe($state);
    Http::assertNothingSent();
})->with(['DEPOSIT', 'VOID']);
it('C348 preserves confirmed cash and a durable delivery if publication to the Telegram queue fails', function ($kind) {
    $f = tgDepositFixture();
    $run = tgDepositOperation($kind, $f);
    Queue::partialMock()->shouldReceive('connection')->with('telegram')->andThrow(new RuntimeException('queue unavailable'));
    $run();
    $state = tgDepositState();
    $run();
    $n = TelegramDispatch::latest('id')->firstOrFail();
    expect($n->deliveries()->sole()->status)->toBe('PENDIENTE')->and($n->deliveries()->sole()->queued_at)->toBeNull()->and(tgDepositState())->toBe($state);
    Http::assertNothingSent();
})->with(['DEPOSIT', 'VOID']);
it('C348 uses only selected event destinations and preserves the unique chat constraint', function () {
    [$admin,$cashier,$account,$fund,$bot,$dest] = tgDepositFixture();
    expect(fn () => TelegramDestination::create(['bot_id' => $bot->id, 'responsible_id' => $admin->id, 'name' => 'Alias del mismo chat', 'chat_id' => '-100123', 'kind' => 'GROUP', 'is_enabled' => true, 'revision' => 1, 'updated_by' => $admin->id]))->toThrow(QueryException::class);
    $unused = TelegramDestination::create(['bot_id' => $bot->id, 'responsible_id' => $admin->id, 'name' => 'Destino no seleccionado', 'chat_id' => '-100999', 'kind' => 'GROUP', 'is_enabled' => true, 'revision' => 1, 'updated_by' => $admin->id]);
    foreach ([$unused] as $d) {
        TelegramConfigurationChange::create(['actor_id' => $admin->id, 'actor_name' => $admin->name, 'kind' => 'DESTINATION_CHECK', 'source_id' => $d->id, 'snapshot' => ['after' => ['revision' => 1], 'bot_revision' => 1, 'status' => 'CONNECTED'], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'fake-check-'.$d->id), 'created_at' => now()]);
    }
    app(RecordAccessoryCashDepositAction::class)->execute($cashier, tgDepositData($account, $fund));
    expect(TelegramDelivery::count())->toBe(1)->and(TelegramDelivery::where('destination_id', $unused->id)->count())->toBe(0);
    Http::assertNothingSent();
});
