<?php

use App\Domain\Notificaciones\Actions\ProcessTelegramDeliveryAction;
use App\Domain\Notificaciones\Actions\RecordLoanTelegramNoticeAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Prestamos\Actions\ChangeLoanTermAction;
use App\Domain\Prestamos\Actions\ManageLoanFundAction;
use App\Domain\Prestamos\Actions\RegisterLoanAction;
use App\Domain\Prestamos\Actions\SaveLoanTypeAction;
use App\Domain\Prestamos\Actions\SettleLoanAction;
use App\Domain\Prestamos\Calculators\LoanSettlementCalculator;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanFund;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanSettlement;
use App\Domain\Prestamos\Models\LoanTermChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Prestamos\LoanPermissionsSeeder;
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

function tgLoanFixture(bool $enabled = true): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'America/La_Paz'));
    config(['telegram.sending_enabled' => $enabled]);
    Http::preventStrayRequests();
    Queue::fake();
    test()->seed([UsuariosPermissionsSeeder::class, LoanPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    $admin = User::factory()->create(['name' => 'Admin prueba']);
    $admin->assignRole('administrador');
    $cashier = User::factory()->create(['name' => 'Lili prueba']);
    $cashier->assignRole('cajera');
    $type = app(SaveLoanTypeAction::class)->execute($admin, ['name' => 'Garrafa', 'rate' => '15', 'is_active' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(ManageLoanFundAction::class)->execute($admin, ['kind' => 'INITIAL', 'amount' => '1000', 'confirmed' => true, 'request_key' => (string) Str::uuid()]);
    $bot = TelegramBotSetting::create(['module' => 'PRESTAMOS', 'name' => 'Bot ficticio', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', 'test'), 'is_enabled' => $enabled, 'revision' => 1, 'updated_by' => $admin->id]);
    $dest = TelegramDestination::create(['bot_id' => $bot->id, 'responsible_id' => $admin->id, 'name' => 'Admin ficticio', 'chat_id' => '-100123', 'kind' => 'GROUP', 'is_enabled' => $enabled, 'revision' => 1, 'updated_by' => $admin->id]);
    foreach (['PR_ALTA', 'PR_RENOVACION', 'PR_PAGO_ENTREGA'] as $code) {
        $e = TelegramEventSetting::create(['bot_id' => $bot->id, 'event_code' => $code, 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $admin->id]);
        $e->destinations()->attach($dest->id, ['destination_revision' => 1]);
    }
    foreach (['BOT_CHECK' => $bot->id, 'DESTINATION_CHECK' => $dest->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $admin->id, 'actor_name' => $admin->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['after' => ['revision' => 1], 'bot_revision' => 1, 'status' => 'CONNECTED'], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }
    $data = ['ci' => 'PRIVADO-CI', 'name' => 'Cliente prueba', 'phone' => '77777777', 'type_id' => $type->id, 'type_revision' => 1, 'description' => 'Garrafa amarilla', 'serial' => 'SERIE-PRIVADA', 'capital' => '200', 'days' => 15, 'start_date' => '2026-10-01', 'request_key' => (string) Str::uuid()];

    return [$admin, $cashier, $type, $data];
}
function tgLoanRenewData(int $days = 15, int $revision = 0, string $kind = 'RENEW', int $paid = 1500): array
{
    return ['kind' => $kind, 'days' => $days, 'periods' => 1, 'expected_revision' => $revision, 'expected_paid_cents' => $paid, 'payment_confirmed' => true, 'reference' => 'REFERENCIA-PRIVADA', 'request_key' => (string) Str::uuid()];
}
function tgLoanSettleData(Loan $loan): array
{
    $quote = app(LoanSettlementCalculator::class)->quote($loan->fresh());

    return ['expected_revision' => $quote['revision'], 'expected_paid_cents' => $quote['paid_cents'], 'payment_confirmed' => true, 'delivery_confirmed' => true, 'request_key' => (string) Str::uuid()];
}

it('omits all three loan notices during pause and never resurrects them on source replay', function () {
    [$admin,$cashier,$type,$data] = tgLoanFixture(false);
    $loan = app(RegisterLoanAction::class)->execute($cashier, $data);
    $renew = tgLoanRenewData();
    $change = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $renew);
    $payment = tgLoanSettleData($loan);
    $settlement = app(SettleLoanAction::class)->execute($cashier, $loan->id, $payment);
    config(['telegram.sending_enabled' => true]);
    expect(app(RegisterLoanAction::class)->execute($cashier, $data)->id)->toBe($loan->id);
    expect(app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $renew)->id)->toBe($change->id);
    expect(app(SettleLoanAction::class)->execute($cashier, $loan->id, $payment)->id)->toBe($settlement->id);
    expect(TelegramDispatch::count())->toBe(3);
    expect(TelegramDispatch::where('status', 'OMITIDA')->whereNull('message')->count())->toBe(3);
    expect(TelegramDelivery::count())->toBe(0);
    expect(LoanFund::sole()->balance_cents)->toBe(103000);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('freezes the saved original terms rather than a draft or later renewal', function (int $days, string $due, int $interest, bool $manual) {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    $data['days'] = $days;
    if ($manual) {
        $data += ['schedule_mode' => 'MANUAL', 'due_date' => '2026-10-19', 'interest_mode' => 'MANUAL', 'manual_interest' => '17.25'];
    }
    $loan = app(RegisterLoanAction::class)->execute($cashier, $data);
    $notice = TelegramDispatch::where('event_code', 'PR_ALTA')->sole();
    expect($notice->module)->toBe('PRESTAMOS');
    expect($notice->message)->toContain('Préstamo registrado', 'Cliente: Cliente prueba', 'Objeto: Garrafa', 'Capital: Bs 200', 'Interés: Bs '.($manual ? '17.25' : (string) $interest), 'Inicio: 01/10/2026', 'Vencimiento: '.$due, 'Lili prueba', '01/10/2026 12:00', 'Bolivia')->not->toContain('PRIVADO-CI', '77777777', 'SERIE-PRIVADA', 'EJEMPLO');
    expect($notice->message)->toContain('/inicio/prestamos/'.$loan->id.'/detalle');
    expect($loan->original_interest_cents)->toBe($manual ? 1725 : $interest * 100);
    expect($notice->media['caption'])->toContain('Plazo registrado: '.($manual ? 19 : $days).' días', 'Vencimiento: '.$due, 'Interés: Bs '.($manual ? '17.25' : (string) $interest));
    app(RegisterLoanAction::class)->execute($cashier, $data);
    expect(TelegramDispatch::count())->toBe(1);
    expect(LoanMovement::where('kind', 'DISBURSEMENT')->count())->toBe(1);
    expect(TelegramDelivery::count())->toBe(1);
    Http::assertNothingSent();
})->with([[15, '15/10/2026', 15, false], [30, '30/10/2026', 30, false], [15, '19/10/2026', 17, true]]);

it('announces each saved renewal or extension without another loan or fund notice', function (string $kind, int $days, string $date, string $due, int $fee, int $added) {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, $data);
    $original = TelegramDispatch::where('event_code', 'PR_ALTA')->sole()->message;
    test()->travelTo(CarbonImmutable::parse($date.' 12:00', 'America/La_Paz'));
    $renew = tgLoanRenewData($days, 0, $kind, 1500 + $fee);
    $event = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $renew);
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $renew);
    $notice = TelegramDispatch::where('event_code', 'PR_RENOVACION')->sole();
    expect($notice->message)->toContain($kind === 'EXTEND' ? 'Ampliación registrada' : 'Renovación registrada', 'Interés cobrado: Bs 15', 'Multa cobrada: Bs '.($fee ? 40 : 0), 'Vencimiento anterior: 15/10/2026', 'Nuevo vencimiento: '.$due, 'Días añadidos: '.$added, 'Operación #'.$event->id, 'Préstamo #'.$loan->id)->not->toContain('REFERENCIA-PRIVADA', 'PRIVADO-CI', 'SERIE-PRIVADA');
    expect($event->summary['late_fee_cents'])->toBe($fee);
    expect($notice->media['caption'])->toContain('Interés cobrado: Bs 15', 'Multa cobrada: Bs '.($fee ? 40 : 0), 'Nuevo vencimiento: '.$due, 'Fecha de operación: '.CarbonImmutable::parse($date)->format('d/m/Y'));
    expect(TelegramDispatch::count())->toBe(2);
    expect(TelegramDispatch::where('event_code', 'PR_ALTA')->sole()->message)->toBe($original);
    expect(Loan::count())->toBe(1);
    expect(LoanTermChange::count())->toBe(1);
    expect(LoanFund::sole()->balance_cents)->toBe(81500 + $fee);
    Http::assertNothingSent();
})->with([['RENEW', 15, '2026-10-13', '30/10/2026', 0, 15], ['RENEW', 30, '2026-10-13', '14/11/2026', 0, 30], ['EXTEND', 30, '2026-10-04', '30/10/2026', 0, 15], ['RENEW', 15, '2026-10-16', '30/10/2026', 4000, 15], ['RENEW', 30, '2026-10-22', '14/11/2026', 4000, 30]]);

it('uses the payment snapshot even after the loan interest projection becomes zero', function (bool $late) {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, $data);
    test()->travelTo(CarbonImmutable::parse($late ? '2026-10-22 23:59:59' : '2026-10-13 12:00', 'America/La_Paz'));
    $payment = tgLoanSettleData($loan);
    $event = app(SettleLoanAction::class)->execute($cashier, $loan->id, $payment);
    app(SettleLoanAction::class)->execute($cashier, $loan->id, $payment);
    $message = TelegramDispatch::where('event_code', 'PR_PAGO_ENTREGA')->sole()->message;
    expect($message)->toContain('Pago y entrega registrados', 'Capital cobrado: Bs 200', 'Interés cobrado: Bs 15', 'Multa cobrada: Bs '.($late ? 40 : 0), 'Total cobrado: Bs '.($late ? 255 : 215), 'Recogido y pagado', 'Operación #'.$event->id);
    expect($loan->fresh()->status)->toBe('RECOGIDO_PAGADO');
    expect(LoanSettlement::count())->toBe(1);
    expect(TelegramDispatch::count())->toBe(2);
    expect(LoanFund::sole()->balance_cents)->toBe($late ? 105500 : 101500);
    Http::assertNothingSent();
})->with([false, true]);

it('keeps two renewal receipts distinct and charges only the latest pending interest at settlement', function () {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, $data);
    $first = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, tgLoanRenewData(30));
    $message = TelegramDispatch::where('event_code', 'PR_RENOVACION')->sole()->message;
    $second = app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, tgLoanRenewData(15, $first->id, 'RENEW', 3000));
    $payment = tgLoanSettleData($loan);
    app(SettleLoanAction::class)->execute($cashier, $loan->id, $payment);
    expect(TelegramDispatch::where('event_code', 'PR_RENOVACION')->count())->toBe(2);
    expect(TelegramDispatch::where('source_reference', 'term:'.$first->id)->sole()->message)->toBe($message);
    expect(TelegramDispatch::where('source_reference', 'term:'.$second->id)->sole()->message)->toContain('Interés cobrado: Bs 30', 'Interés pendiente: Bs 15');
    expect(TelegramDispatch::where('event_code', 'PR_PAGO_ENTREGA')->sole()->message)->toContain('Total cobrado: Bs 215', 'Interés cobrado: Bs 15');
    expect(Loan::count())->toBe(1);
    expect(LoanFund::sole()->balance_cents)->toBe(106000);
    expect(TelegramDispatch::count())->toBe(4);
});

it('rolls back the source and outbox on command interruption then retries cleanly', function (string $kind) {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    $loan = $kind === 'REGISTER' ? null : app(RegisterLoanAction::class)->execute($cashier, $data);
    $input = match ($kind) {
        'REGISTER' => $data,'TERM_CHANGE' => tgLoanRenewData(),default => tgLoanSettleData($loan)
    };
    $run = fn () => match ($kind) {
        'REGISTER' => app(RegisterLoanAction::class)->execute($cashier, $input),'TERM_CHANGE' => app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $input),default => app(SettleLoanAction::class)->execute($cashier, $loan->id, $input)
    };
    $balance = LoanFund::sole()->balance_cents;
    $notices = TelegramDispatch::count();
    $movements = LoanMovement::count();
    DB::statement("CREATE TRIGGER fail_notice_command BEFORE INSERT ON loan_commands WHEN NEW.kind = '$kind' BEGIN SELECT RAISE(ABORT,'interruption'); END");
    expect($run)->toThrow(QueryException::class);
    expect(TelegramDispatch::count())->toBe($notices);
    expect(TelegramDelivery::count())->toBe($notices);
    expect(LoanFund::sole()->balance_cents)->toBe($balance);
    expect(LoanMovement::count())->toBe($movements);
    DB::statement('DROP TRIGGER fail_notice_command');
    $run();
    $run();
    expect(TelegramDispatch::count())->toBe($notices + 1);
    expect(LoanMovement::count())->toBe($movements + 1);
    Http::assertNothingSent();
})->with(['REGISTER', 'TERM_CHANGE', 'SETTLEMENT']);

it('does not notify an invalid unauthorized stale or expired loan operation', function () {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    $outsider = User::factory()->create();
    expect(fn () => app(RegisterLoanAction::class)->execute($outsider, $data))->toThrow(AuthorizationException::class);
    expect(fn () => app(RegisterLoanAction::class)->execute($cashier, array_replace($data, ['capital' => '2000'])))->toThrow(ValidationException::class);
    expect(TelegramDispatch::count())->toBe(0);
    $loan = app(RegisterLoanAction::class)->execute($cashier, $data);
    expect(fn () => app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, tgLoanRenewData(15, 99)))->toThrow(ValidationException::class);
    $bad = tgLoanSettleData($loan);
    $bad['delivery_confirmed'] = false;
    expect(fn () => app(SettleLoanAction::class)->execute($cashier, $loan->id, $bad))->toThrow(ValidationException::class);
    test()->travelTo(CarbonImmutable::parse('2026-10-23 12:00', 'America/La_Paz'));
    expect(fn () => app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, tgLoanRenewData()))->toThrow(ValidationException::class);
    expect(TelegramDispatch::count())->toBe(1);
    expect(LoanFund::sole()->balance_cents)->toBe(80000);
    Http::assertNothingSent();
});

it('never backfills historical notices by replaying already confirmed commands', function () {
    [$admin,$cashier,$type,$data] = tgLoanFixture(false);
    $loan = app(RegisterLoanAction::class)->execute($cashier, $data);
    $renew = tgLoanRenewData();
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $renew);
    $payment = tgLoanSettleData($loan);
    app(SettleLoanAction::class)->execute($cashier, $loan->id, $payment);
    TelegramDispatch::query()->delete();
    config(['telegram.sending_enabled' => true]);
    app(RegisterLoanAction::class)->execute($cashier, $data);
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $renew);
    app(SettleLoanAction::class)->execute($cashier, $loan->id, $payment);
    expect(TelegramDispatch::count())->toBe(0);
    expect(LoanFund::sole()->balance_cents)->toBe(103000);
    Http::assertNothingSent();
});

it('retries a simulated loan delivery without repeating the loan renewal or payment', function (string $code) {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, $data);
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, tgLoanRenewData());
    app(SettleLoanAction::class)->execute($cashier, $loan->id, tgLoanSettleData($loan));
    $delivery = TelegramDispatch::where('event_code', $code)->sole()->deliveries()->sole();
    Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false, 'error_code' => 500], 500)->push(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => -100123]]])]);
    $action = app(ProcessTelegramDeliveryAction::class);
    $action->execute($delivery->id);
    test()->travel(60)->seconds();
    $action->execute($delivery->id);
    $action->execute($delivery->id);
    expect($delivery->fresh()->status)->toBe('ENVIADA');
    expect(Loan::count())->toBe(1);
    expect(LoanTermChange::count())->toBe(1);
    expect(LoanSettlement::count())->toBe(1);
    expect(LoanFund::sole()->balance_cents)->toBe(103000);
    expect(LoanMovement::whereIn('kind', ['DISBURSEMENT', 'TERM_PAYMENT', 'SETTLEMENT'])->count())->toBe(3);
    expect(TelegramDispatch::count())->toBe(3);
    Http::assertSentCount(2);
})->with(['PR_ALTA', 'PR_RENOVACION', 'PR_PAGO_ENTREGA']);

it('rolls back finances if the new loan notice cannot be persisted', function (string $code) {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    $loan = $code === 'PR_ALTA' ? null : app(RegisterLoanAction::class)->execute($cashier, $data);
    $input = match ($code) {
        'PR_ALTA' => $data,'PR_RENOVACION' => tgLoanRenewData(),default => tgLoanSettleData($loan)
    };
    $run = fn () => match ($code) {
        'PR_ALTA' => app(RegisterLoanAction::class)->execute($cashier, $input),'PR_RENOVACION' => app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $input),default => app(SettleLoanAction::class)->execute($cashier, $loan->id, $input)
    };
    $balance = LoanFund::sole()->balance_cents;
    $count = TelegramDispatch::count();
    DB::statement("CREATE TRIGGER fail_loan_notice BEFORE INSERT ON telegram_dispatches WHEN NEW.event_code = '$code' BEGIN SELECT RAISE(ABORT,'outbox interrupted'); END");
    expect($run)->toThrow(QueryException::class);
    expect(LoanFund::sole()->balance_cents)->toBe($balance);
    expect(TelegramDispatch::count())->toBe($count);
    expect(LoanTermChange::count())->toBe(0);
    expect(LoanSettlement::count())->toBe(0);
    if ($loan) {
        expect($loan->fresh()->status)->toBe('ACTIVO');
        expect($loan->fresh()->due_date->toDateString())->toBe('2026-10-15');
    } else {
        expect(Loan::count())->toBe(0);
    }
    DB::statement('DROP TRIGGER fail_loan_notice');
    $run();
    $run();
    expect(TelegramDispatch::count())->toBe($count + 1);
    Http::assertNothingSent();
})->with(['PR_ALTA', 'PR_RENOVACION', 'PR_PAGO_ENTREGA']);

it('keeps a confirmed loan and its durable delivery if publishing the queue fails', function () {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    Queue::partialMock()->shouldReceive('connection')->with('telegram')->andThrow(new RuntimeException('queue unavailable'));
    $loan = app(RegisterLoanAction::class)->execute($cashier, $data);
    expect(Loan::count())->toBe(1);
    expect(LoanFund::sole()->balance_cents)->toBe(80000);
    expect(TelegramDispatch::sole()->deliveries()->sole()->queued_at)->toBeNull();
    expect(TelegramDispatch::sole()->deliveries()->sole()->status)->toBe('PENDIENTE');
    Http::assertNothingSent();
});

it('bounds long descriptive fields and removes user links without exposing structured private fields', function () {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    $data['name'] = "Cliente https://example.invalid @atencion\n".str_repeat('Largo ', 15);
    $data['description'] = "https://example.invalid @todos\n".str_repeat('Detalle ', 220);
    app(RegisterLoanAction::class)->execute($cashier, $data);
    $message = TelegramDispatch::sole()->message;
    expect(mb_strlen($message))->toBeLessThanOrEqual(4096);
    expect($message)->toContain('[enlace omitido]')->not->toContain('example.invalid', '@atencion', '@todos', 'PRIVADO-CI', 'SERIE-PRIVADA', '77777777');
    expect(Loan::sole()->object_snapshot['description'])->toBe(trim($data['description']));
    Http::assertNothingSent();
});

it('reports four prepaid monthly periods using the single persisted renewal receipt', function () {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    $data['days'] = 30;
    $loan = app(RegisterLoanAction::class)->execute($cashier, $data);
    $renew = tgLoanRenewData(30, 0, 'RENEW', 12000);
    $renew['periods'] = 4;
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $renew);
    expect(TelegramDispatch::where('event_code', 'PR_RENOVACION')->sole()->message)->toContain('Total cobrado: Bs 120', 'Interés cobrado: Bs 120', 'Interés pendiente: Bs 30', 'Periodos: 4', 'Días añadidos: 120', 'Nuevo vencimiento: 27/02/2027');
    expect(Loan::count())->toBe(1);
    expect(LoanFund::sole()->balance_cents)->toBe(92000);
    Http::assertNothingSent();
});

it('sends one frozen photo for each saved registration renewal and settlement without new financial records on replay', function () {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, $data);
    $renew = tgLoanRenewData();
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $renew);
    $payment = tgLoanSettleData($loan);
    app(SettleLoanAction::class)->execute($cashier, $loan->id, $payment);
    $snapshots = TelegramDispatch::orderBy('id')->get()->mapWithKeys(fn ($n) => [$n->id => $n->media])->all();
    foreach (TelegramDispatch::all() as $notice) {
        expect($notice->media['filename'])->toBe('logoReciboPrestamo.png')->and(mb_strlen($notice->media['caption']))->toBeLessThanOrEqual(1024);
        expect($notice->media['caption'])->not->toContain('PRIVADO-CI', '77777777', 'SERIE-PRIVADA', 'REFERENCIA-PRIVADA');
        expect(DB::table('telegram_dispatches')->where('id', $notice->id)->value('media'))->not->toContain('Cliente prueba', 'logoReciboPrestamo');
    }
    $token = TelegramBotSetting::where('module', 'PRESTAMOS')->sole()->token;
    Http::fake(['https://api.telegram.org/bot'.$token.'/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => -100123]]])]);
    $cashier->update(['name' => 'Nombre posterior']);
    foreach (TelegramDelivery::all() as $delivery) {
        app(ProcessTelegramDeliveryAction::class)->execute($delivery->id);
        app(ProcessTelegramDeliveryAction::class)->execute($delivery->id);
    }
    app(RegisterLoanAction::class)->execute($cashier, $data);
    app(ChangeLoanTermAction::class)->execute($cashier, $loan->id, $renew);
    app(SettleLoanAction::class)->execute($cashier, $loan->id, $payment);
    expect(TelegramDispatch::count())->toBe(3)->and(TelegramDelivery::where('status', 'ENVIADA')->count())->toBe(3);
    expect(Loan::count())->toBe(1)->and(LoanTermChange::count())->toBe(1)->and(LoanSettlement::count())->toBe(1)->and(LoanFund::sole()->balance_cents)->toBe(103000);
    expect(TelegramDispatch::orderBy('id')->get()->mapWithKeys(fn ($n) => [$n->id => $n->media])->all())->toBe($snapshots);
    Http::assertSentCount(3);
});

it('does not store photo bytes or queue messages for paused loans', function () {
    [$admin,$cashier,$type,$data] = tgLoanFixture(false);
    app(RegisterLoanAction::class)->execute($cashier, $data);
    expect(TelegramDispatch::sole()->media)->toBeNull()->and(TelegramDelivery::count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps old text notices unchanged when the loan adapter is replayed after the visual upgrade', function () {
    [$admin,$cashier,$type,$data] = tgLoanFixture();
    $loan = app(RegisterLoanAction::class)->execute($cashier, $data);
    $old = TelegramDispatch::sole();
    $old->update(['message' => 'Texto histórico conservado', 'media' => null]);
    DB::transaction(fn () => app(RecordLoanTelegramNoticeAction::class)->registration($loan->id));
    expect($old->fresh()->message)->toBe('Texto histórico conservado')->and($old->fresh()->media)->toBeNull();
    expect(TelegramDispatch::count())->toBe(1)->and(TelegramDelivery::count())->toBe(1);
    Http::assertNothingSent();
});
