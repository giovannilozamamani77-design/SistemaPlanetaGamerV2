<?php

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
use App\Domain\Cajas\Models\CashBoxType;
use App\Domain\Cajas\Queries\CashBoxTypeCatalog;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Notificaciones\Actions\ProcessTelegramDeliveryAction;
use App\Domain\Notificaciones\Actions\QueueTelegramNoticeAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Services\CashBoxTelegramMessage;
use App\Domain\Notificaciones\Services\CashBoxTelegramPhoto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Cajas\CajasPermissionsSeeder;
use Database\Seeders\ControlHorario\ControlHorarioPermissionsSeeder;
use Database\Seeders\Incidencias\IncidenciasPermissionsSeeder;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function tgCashFixture(bool $enabled = false, bool $open = true): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-29 08:00', 'America/La_Paz'));
    config(['telegram.sending_enabled' => $enabled, 'cajas.schedule_controls_enabled' => false]);
    Http::preventStrayRequests();
    Queue::fake();
    test()->seed([UsuariosPermissionsSeeder::class, CajasPermissionsSeeder::class, ControlHorarioPermissionsSeeder::class, IncidenciasPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    $admin = User::factory()->create(['name' => 'Admin prueba', 'pin_hash' => Hash::make('1234')]);
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['name' => 'Lili prueba', 'pin_hash' => Hash::make('1234')]);
    $cashier->assignRole('cajera');
    $box = CashBox::factory()->create(['name' => 'Tablet Telegram prueba']);
    $accounts = [];
    foreach (CashBoxComponents::forType('BCP') as $code => $name) {
        $accounts[$code] = ['name' => $name, 'initial' => match ($code) {
            'bcp' => '15000','efectivo' => '2000',default => '3000'
        }];
    }
    app(ConfigureCashBoxAction::class)->execute($admin, $box, ['accounts' => $accounts, 'base_capital' => '20000', 'shifts' => CashBoxComponents::defaultShifts(), 'expected_revision' => $box->revision, 'reason' => 'Configuración ficticia', 'request_key' => (string) Str::uuid()]);
    $bot = TelegramBotSetting::create(['module' => 'CAJAS', 'name' => 'Bot ficticio', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', 'test'), 'is_enabled' => $enabled, 'revision' => 1, 'updated_by' => $admin->id]);
    $dest = TelegramDestination::create(['bot_id' => $bot->id, 'responsible_id' => $admin->id, 'name' => 'Admin ficticio', 'chat_id' => '-100123', 'kind' => 'GROUP', 'is_enabled' => $enabled, 'revision' => 1, 'updated_by' => $admin->id]);
    foreach (['CJ_APERTURA', 'CJ_CIERRE'] as $code) {
        $e = TelegramEventSetting::create(['bot_id' => $bot->id, 'event_code' => $code, 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $admin->id]);
        $e->destinations()->attach($dest->id, ['destination_revision' => 1]);
    }
    foreach (['BOT_CHECK' => $bot->id, 'DESTINATION_CHECK' => $dest->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $admin->id, 'actor_name' => $admin->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['after' => ['revision' => 1], 'bot_revision' => 1, 'status' => 'CONNECTED'], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }
    $access = app(StartCashBoxAccessAction::class)->execute($cashier, $box, '1234', (string) Str::uuid());
    $input = ['balances' => ['bcp' => '15000', 'efectivo' => '2000', 'yape' => '3000'], 'request_key' => (string) Str::uuid()];
    $session = $open ? app(OpenCashBoxAction::class)->execute($cashier, $access->id, $input) : null;

    return [$admin, $cashier, $box, $session, $access, $input];
}
function tgCashCloseInput(User $admin, CashBoxSession $session, bool $liquidate = true, int $shortage = 0): array
{
    if ($liquidate) {
        app(RecordQuickCashBoxCommissionAction::class)->execute($session->user, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
        test()->travelTo(now('America/La_Paz')->setTime(20, 0));
    } else {
        test()->travelTo(now('America/La_Paz')->setTime(14, 0));
    }
    $account = $liquidate ? app(SetCashBoxCommissionAccountAction::class)->execute($admin, ['destination' => '70000000', 'holder_name' => 'Admin ficticio', 'reason' => 'Cuenta ficticia', 'pin' => '1234', 'request_key' => (string) Str::uuid()]) : null;

    return ['period' => $liquidate ? 'tarde' : 'manana', 'balances' => ['efectivo' => (string) (2000 + ($liquidate ? 50 : 0) - $shortage), 'bcp' => '15000', 'yape' => '3000'], 'readings' => ['general' => '100', 'fixed' => '50'], 'expected_revision' => $session->fresh()->revision, 'liquidate' => $liquidate, 'account_id' => $account, 'amount_cents' => $liquidate ? (50 - $shortage) * 100 : 0, 'transferred' => $liquidate, 'operation_reference' => $liquidate ? 'SIMULATED-TRANSFER' : '', 'reason' => $shortage ? 'Faltante de prueba' : '', 'cash_count' => null, 'request_key' => (string) Str::uuid()];
}
it('records paused openings and closings as omitted without queueing or resending on replay', function () {
    [$admin,$cashier,$box,$session,$access,$input] = tgCashFixture();
    $data = tgCashCloseInput($admin, $session);
    $close = app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    expect(TelegramDispatch::count())->toBe(2)->and(TelegramDispatch::where('status', 'OMITIDA')->count())->toBe(2)->and(TelegramDelivery::count())->toBe(0);
    expect(TelegramDispatch::where('event_code', 'CJ_CIERRE')->sole()->message)->toBeNull()->and($session->fresh()->status)->toBe('CERRADA');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
it('freezes one opening message from counted saved balances and ignores recovery or form mutation', function () {
    [$admin,$cashier,$box,$session,$access,$input] = tgCashFixture(true);
    $dispatch = TelegramDispatch::where('event_code', 'CJ_APERTURA')->sole();
    expect($dispatch->message)->toContain('Apertura registrada')->toContain('Lili prueba')->toContain('Total contado: Bs 20000')->toContain('29/10/2026 08:00')->not->toContain('1234')->not->toContain('EJEMPLO');
    app(OpenCashBoxAction::class)->execute($cashier, $access->id, $input);
    $newAccess = app(StartCashBoxAccessAction::class)->execute($cashier, $box, '1234', (string) Str::uuid());
    app(OpenCashBoxAction::class)->execute($cashier, $newAccess->id, ['balances' => $input['balances'], 'request_key' => (string) Str::uuid()]);
    expect(TelegramDispatch::count())->toBe(1)->and(TelegramDelivery::count())->toBe(1);
    $original = $dispatch->message;
    app(RecordQuickCashBoxCommissionAction::class)->execute($cashier, $session->id, ['fee_type' => 'FACTURAS', 'bill_count' => 100, 'request_key' => (string) Str::uuid()]);
    $cashier->update(['name' => 'Nombre posterior']);
    expect($dispatch->fresh()->message)->toBe($original);
    Http::assertNothingSent();
});
it('includes the full shortage and the real liquidation in a single closing message', function () {
    [$admin,$cashier,$box,$session] = tgCashFixture(true);
    $data = tgCashCloseInput($admin, $session, true, 20);
    $close = app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    $notice = TelegramDispatch::where('event_code', 'CJ_CIERRE')->sole();
    expect($notice->message)->toContain('Esperado: Bs 20050')->toContain('Contado: Bs 20030')->toContain('Faltante registrado: Bs 20')->toContain('Liquidación realizada: Bs 30')->toContain('Pendiente de autorización')->toContain('Responsable: Lili prueba');
    expect(TelegramDispatch::count())->toBe(2)->and(TelegramDispatch::whereIn('event_code', ['CJ_INCIDENCIA', 'CJ_LIQUIDACION'])->count())->toBe(0)->and(EconomicIncident::sole()->amount_cents)->toBe(2000)->and($close->liquidated_cents)->toBe(3000);
    app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    expect(TelegramDispatch::count())->toBe(2)->and(TelegramDelivery::count())->toBe(2);
    Http::assertNothingSent();
});
it('states no liquidation when the selected shift does not liquidate', function () {
    [$admin,$cashier,$box,$session] = tgCashFixture(true);
    $data = tgCashCloseInput($admin, $session, false);
    app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    expect(TelegramDispatch::where('event_code', 'CJ_CIERRE')->sole()->message)->toContain('Turno: Mañana')->toContain('Liquidación: no requerida')->toContain('Cerrada y disponible');
    Http::assertNothingSent();
});
it('attributes a new opening shortage to the prior cashier without a separate debt notification', function ($delta) {
    [$admin,$cashier,$box,$session] = tgCashFixture(true);
    $close = app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, tgCashCloseInput($admin, $session, false));
    $next = User::factory()->create(['name' => 'Ana siguiente', 'pin_hash' => Hash::make('1234')]);
    $next->assignRole('cajera');
    $access = app(StartCashBoxAccessAction::class)->execute($next, $box, '1234', (string) Str::uuid());
    $opened = app(OpenCashBoxAction::class)->execute($next, $access->id, ['balances' => ['bcp' => '15000', 'efectivo' => (string) (2000 + $delta), 'yape' => '3000'], 'request_key' => (string) Str::uuid()]);
    $notice = TelegramDispatch::where('event_code', 'CJ_APERTURA')->latest('id')->first();
    if ($delta < 0) {
        expect($notice->message)->toContain('Faltante registrado: Bs 10')->toContain('Responsable anterior: Lili prueba');
        expect(EconomicIncident::sole()->user_id)->toBe($cashier->id);
    } else {
        expect($notice->message)->toContain('Sobrante registrado: Bs 10');
        expect(EconomicIncident::count())->toBe(0);
    }
    expect(TelegramDispatch::count())->toBe(3)->and($opened->operating_capital_cents)->toBe(2000000);
    Http::assertNothingSent();
})->with([-10, 10]);
it('does not create notices for failed opening or closing validations', function () {
    [$admin,$cashier,$box,$session,$access,$input] = tgCashFixture(true, false);
    $bad = $input;
    $bad['balances']['efectivo'] = '-1';
    expect(fn () => app(OpenCashBoxAction::class)->execute($cashier, $access->id, $bad))->toThrow(ValidationException::class);
    expect(TelegramDispatch::count())->toBe(0);
    $session = app(OpenCashBoxAction::class)->execute($cashier, $access->id, $input);
    $data = tgCashCloseInput($admin, $session);
    $data['amount_cents'] = 999;
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data))->toThrow(ValidationException::class);
    expect(TelegramDispatch::count())->toBe(1)->and(CashBoxClose::count())->toBe(0);
    Http::assertNothingSent();
});
it('rolls back opening together with its notice if the source transaction is interrupted', function () {
    [$admin,$cashier,$box,$session,$access,$input] = tgCashFixture(true, false);
    try {
        DB::transaction(function () use ($cashier, $access, $input) {
            app(OpenCashBoxAction::class)->execute($cashier, $access->id, $input);
            throw new RuntimeException('interrupt');
        });
    } catch (RuntimeException $e) {
    }
    expect(CashBoxSession::count())->toBe(0)->and(TelegramDispatch::count())->toBe(0)->and(TelegramDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    app(OpenCashBoxAction::class)->execute($cashier, $access->id, $input);
    expect(TelegramDispatch::count())->toBe(1);
});
it('rolls back closing liquidation and debt with the notice then allows a clean retry', function () {
    [$admin,$cashier,$box,$session] = tgCashFixture(true);
    $data = tgCashCloseInput($admin, $session, true, 20);
    DB::statement("CREATE TRIGGER fail_closing_notice BEFORE INSERT ON telegram_dispatches WHEN NEW.event_code = 'CJ_CIERRE' BEGIN SELECT RAISE(ABORT, 'test'); END");
    expect(fn () => app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data))->toThrow(QueryException::class);
    expect(CashBoxClose::count())->toBe(0)->and(DB::table('cash_box_liquidations')->count())->toBe(0)->and(EconomicIncident::count())->toBe(0)->and(TelegramDispatch::count())->toBe(1)->and($session->fresh()->status)->toBe('ABIERTA');
    DB::statement('DROP TRIGGER fail_closing_notice');
    app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    expect(CashBoxClose::count())->toBe(1)->and(TelegramDispatch::count())->toBe(2);
    Http::assertNothingSent();
});
it('retries only the queued closing message after a simulated API rejection without repeating finances', function () {
    [$admin,$cashier,$box,$session] = tgCashFixture(true);
    $close = app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, tgCashCloseInput($admin, $session, true, 20));
    $notice = TelegramDispatch::where('event_code', 'CJ_CIERRE')->sole();
    $delivery = $notice->deliveries()->sole();
    Http::fake(['*' => Http::sequence()->push(['ok' => false, 'error_code' => 500], 500)->push(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => -100123]]])]);
    $process = app(ProcessTelegramDeliveryAction::class);
    $process->execute($delivery->id);
    test()->travel(60)->seconds();
    $process->execute($delivery->id);
    $process->execute($delivery->id);
    expect($delivery->fresh()->status)->toBe('ENVIADA')->and(CashBoxClose::count())->toBe(1)->and(DB::table('cash_box_liquidations')->count())->toBe(1)->and(EconomicIncident::count())->toBe(1)->and(TelegramDispatch::count())->toBe(2);
    Http::assertSentCount(2);
});

it('keeps messages within Telegram limits for a valid type with fifty long account labels', function () {
    [$admin,$cashier,$box,$session] = tgCashFixture(true);
    $accounts = [];
    $balances = [];
    for ($n = 0; $n < 50; $n++) {
        $key = 'account_'.$n;
        $accounts[$key] = ['name' => str_repeat('Cuenta larga ', 7), 'initial_cents' => 99999999999];
        $balances[$key] = 99999999999;
    }
    $configuration = $session->configuration;
    $configuration->accounts = $accounts;
    $session->setRelation('configuration', $configuration);
    $session->opening_balances = $balances;
    $session->expected_balances = $balances;
    $message = app(CashBoxTelegramMessage::class)->opening($session);
    expect(mb_strlen($message))->toBeLessThanOrEqual(4096)->and($message)->toContain('cuentas más');
    Http::assertNothingSent();
});
it('does not notify for pin checks or unauthorized attempts and preserves pause after a replay', function () {
    [$admin,$cashier,$box,$session,$access,$input] = tgCashFixture(false, false);
    expect(TelegramDispatch::count())->toBe(0);
    $other = User::factory()->create(['pin_hash' => Hash::make('1234')]);
    $other->assignRole('cajera');
    expect(fn () => app(OpenCashBoxAction::class)->execute($other, $access->id, $input))->toThrow(AuthorizationException::class);
    expect(TelegramDispatch::count())->toBe(0);
    $session = app(OpenCashBoxAction::class)->execute($cashier, $access->id, $input);
    config(['telegram.sending_enabled' => true]);
    app(OpenCashBoxAction::class)->execute($cashier, $access->id, $input);
    expect(TelegramDispatch::count())->toBe(1)->and(TelegramDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
it('does not let a queue publishing failure reverse the financial closing', function () {
    [$admin,$cashier,$box,$session] = tgCashFixture(true);
    $data = tgCashCloseInput($admin, $session, true, 20);
    Queue::partialMock()->shouldReceive('connection')->with('telegram')->andThrow(new RuntimeException('queue unavailable'));
    $close = app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    expect($close->liquidated_cents)->toBe(3000)->and($session->fresh()->status)->toBe('CIERRE_REGISTRADO')->and(EconomicIncident::sole()->amount_cents)->toBe(2000);
    $notice = TelegramDispatch::where('event_code', 'CJ_CIERRE')->sole();
    expect($notice->deliveries()->sole()->queued_at)->toBeNull();
    Http::assertNothingSent();
});
it('reports a closing surplus using the persisted amount and financial liquidation without another notice', function () {
    [$admin,$cashier,$box,$session] = tgCashFixture(true);
    $data = tgCashCloseInput($admin, $session, true, -10);
    $close = app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    expect(TelegramDispatch::where('event_code', 'CJ_CIERRE')->sole()->message)->toContain('Sobrante registrado: Bs 10')->toContain('Faltante registrado: Bs 0')->toContain('Liquidación realizada: Bs 60');
    expect(TelegramDispatch::count())->toBe(2)->and($close->liquidated_cents)->toBe(6000);
    Http::assertNothingSent();
});

it('does not backfill historical openings or closings when their existing requests are replayed', function () {
    [$admin,$cashier,$box,$session,$access,$input] = tgCashFixture(false);
    $data = tgCashCloseInput($admin, $session, false);
    $close = app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    TelegramDispatch::query()->delete();
    app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    expect(TelegramDispatch::count())->toBe(0)->and(CashBoxClose::count())->toBe(1);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
it('does not claim a transfer when the shortage consumes the entire commission', function () {
    [$admin,$cashier,$box,$session] = tgCashFixture(true);
    $data = tgCashCloseInput($admin, $session, true, 50);
    $close = app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    $message = TelegramDispatch::where('event_code', 'CJ_CIERRE')->sole()->message;
    expect($message)->toContain('Faltante registrado: Bs 50')->toContain('Liquidación cumplida: Bs 0')->toContain('Sin importe para transferir');
    expect($close->liquidated_cents)->toBe(0)->and(DB::table('cash_box_liquidations')->count())->toBe(0)->and(EconomicIncident::sole()->amount_cents)->toBe(5000);
    Http::assertNothingSent();
});

it('freezes one photo and caption per opening and closing without duplicating on replay', function () {
    [$admin,$cashier,$box,$session,$access,$input] = tgCashFixture(true);
    $open = TelegramDispatch::where('event_code', 'CJ_APERTURA')->sole();
    expect($open->media['filename'])->toBe('agente bcp cajas.jpg')->and($open->media['caption'])->toContain('🟢 CAJA ABIERTA', 'Total contado: Bs 20000');
    expect(DB::table('telegram_dispatches')->where('id', $open->id)->value('media'))->not->toContain('CAJA ABIERTA', 'agente bcp');
    $data = tgCashCloseInput($admin, $session, true, 20);
    app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    $close = TelegramDispatch::where('event_code', 'CJ_CIERRE')->sole();
    expect($close->media['caption'])->toContain('🟠 CIERRE PENDIENTE', 'Contado: Bs 20030', 'Faltante registrado: Bs 20', 'Liquidación realizada: Bs 30');
    app(ConfirmCashBoxClosingAction::class)->execute($cashier, $session->id, $data);
    Http::fake(['https://api.telegram.org/bot'.TelegramBotSetting::where('module', 'CAJAS')->sole()->token.'/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 77, 'chat' => ['id' => -100123]]])]);
    $delivery = $close->deliveries()->sole();
    $box->update(['name' => 'Caja renombrada después', 'type' => 'TIGO_MONEY']);
    expect($close->fresh()->media)->toBe($close->media)->and($close->fresh()->message)->not->toContain('Caja renombrada después');
    app(ProcessTelegramDeliveryAction::class)->execute($delivery->id);
    app(ProcessTelegramDeliveryAction::class)->execute($delivery->id);
    expect($delivery->fresh()->status)->toBe('ENVIADA')->and(TelegramDispatch::count())->toBe(2)->and(TelegramDelivery::count())->toBe(2);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto'));
});

it('does not retain a photo in paused cash box notices', function () {
    tgCashFixture(false);
    expect(TelegramDispatch::sole()->media)->toBeNull();
    Http::assertNothingSent();
});

it('uses the bank family image for derived cash box types', function () {
    [$admin,$cashier,$box] = tgCashFixture(true);
    CashBoxType::create(['code' => 'BCP_PHOTO_CUSTOM', 'name' => 'BCP adicional', 'family' => 'BCP', 'components' => [], 'is_active' => true]);
    app(CashBoxTypeCatalog::class)->refresh();
    $box->update(['type' => 'BCP_PHOTO_CUSTOM']);
    expect(app(CashBoxTelegramPhoto::class)->snapshot($box, 'CAJA ABIERTA')['filename'])->toBe('agente bcp cajas.jpg');
    Http::assertNothingSent();
});

it('rejects a changed photo for the same fact without duplicating the notice', function () {
    tgCashFixture(true);
    $notice = TelegramDispatch::sole();
    $media = $notice->media;
    $media['caption'] .= ' cambiado';
    expect(fn () => app(QueueTelegramNoticeAction::class)->execute('CAJAS', 'CJ_APERTURA', $notice->source_reference, $notice->message, $media))->toThrow(ValidationException::class);
    expect(TelegramDispatch::count())->toBe(1)->and(TelegramDelivery::count())->toBe(1)->and($notice->fresh()->media)->toBe($notice->media);
    expect($notice->toArray())->not->toHaveKey('media');
    Http::assertNothingSent();
});
