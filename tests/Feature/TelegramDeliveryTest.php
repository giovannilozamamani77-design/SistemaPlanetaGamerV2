<?php

use App\Domain\Notificaciones\Actions\ProcessTelegramDeliveryAction;
use App\Domain\Notificaciones\Actions\QueueTelegramNoticeAction;
use App\Domain\Notificaciones\Actions\RetryTelegramDeliveryAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDeliveryAttempt;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Queries\TelegramDeliveryQuery;
use App\Domain\Notificaciones\Services\TelegramDeliveryPublisher;
use App\Filament\Pages\Notificaciones\TelegramDeliveryHistory;
use App\Filament\Pages\Notificaciones\TelegramSettings;
use App\Infrastructure\Telegram\TelegramMessageClient;
use App\Jobs\SendTelegramDelivery;
use App\Models\User;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function sendFixture(bool $enabled = true): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->freezeTime();
    Http::preventStrayRequests();
    Queue::fake();
    config(['telegram.sending_enabled' => $enabled]);
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);
    $b = TelegramBotSetting::create(['module' => 'CAJAS', 'name' => 'Bot ficticio', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', 'test'), 'is_enabled' => $enabled, 'revision' => 1, 'updated_by' => $u->id]);
    $d = TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $u->id, 'name' => 'Destino ficticio', 'chat_id' => '-100123', 'kind' => 'GROUP', 'is_enabled' => $enabled, 'revision' => 1, 'updated_by' => $u->id]);
    $e = TelegramEventSetting::create(['bot_id' => $b->id, 'event_code' => 'CJ_CIERRE', 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $u->id]);
    $e->destinations()->attach($d->id, ['destination_revision' => 1]);
    foreach (['BOT_CHECK' => $b->id, 'DESTINATION_CHECK' => $d->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['after' => ['revision' => 1], 'bot_revision' => 1, 'status' => 'CONNECTED'], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }

    return [$u, $b, $d, $e];
}
function sendIntent(string $reference = 'close:123:registered')
{
    return app(QueueTelegramNoticeAction::class)->execute('CAJAS', 'CJ_CIERRE', $reference, 'CIERRE SIMULADO · Bs 30');
}
function fakeSend(array $response, int $status = 200): void
{
    Http::fake(['https://api.telegram.org/bot123456:'.str_repeat('A', 35).'/sendMessage' => Http::response($response, $status)]);
}
function processSend(): void
{
    app(ProcessTelegramDeliveryAction::class)->execute(TelegramDelivery::sole()->id);
}
it('omits paused intents without storing a body queueing or accumulating a backlog', function () {
    sendFixture(false);
    $d = sendIntent();
    sendIntent();
    expect($d->status)->toBe('OMITIDA')->and($d->message)->toBeNull();
    expect(TelegramDispatch::count())->toBe(1)->and(TelegramDelivery::count())->toBe(0);
    config(['telegram.sending_enabled' => true]);
    sendIntent();
    expect(TelegramDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
it('deduplicates a fact and its destination and freezes encrypted snapshots', function () {
    [$u,$b,$d] = sendFixture();
    $a = sendIntent();
    sendIntent();
    expect(TelegramDispatch::count())->toBe(1)->and(TelegramDelivery::count())->toBe(1);
    expect(DB::table('telegram_dispatches')->value('message'))->not->toContain('CIERRE SIMULADO');
    expect(DB::table('telegram_deliveries')->value('chat_id'))->not->toBe($d->chat_id);
    expect(serialize(new SendTelegramDelivery(TelegramDelivery::sole()->id)))->not->toContain($b->token)->not->toContain('CIERRE SIMULADO');
    expect(fn () => app(QueueTelegramNoticeAction::class)->execute('CAJAS', 'CJ_CIERRE', 'close:123:registered', 'Different'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});
it('records a successful simulated delivery only once despite duplicate jobs', function () {
    sendFixture();
    sendIntent();
    fakeSend(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => -100123]]]);
    processSend();
    processSend();
    expect(TelegramDelivery::sole()->status)->toBe('ENVIADA')->and(TelegramDelivery::sole()->message_id)->toBe('99')->and(TelegramDeliveryAttempt::count())->toBe(1);
    Http::assertSentCount(1);
});
it('retries an explicit transient failure at most three times one minute apart', function () {
    sendFixture();
    sendIntent();
    fakeSend(['ok' => false, 'error_code' => 500]);
    processSend();
    processSend();
    Http::assertSentCount(1);
    expect(TelegramDelivery::sole()->status)->toBe('REINTENTO_PENDIENTE');
    test()->travel(60)->seconds();
    processSend();
    test()->travel(60)->seconds();
    processSend();
    processSend();
    expect(TelegramDelivery::sole()->status)->toBe('ERROR_DEFINITIVO')->and(TelegramDeliveryAttempt::count())->toBe(3);
    Http::assertSentCount(3);
});
it('honors a longer retry_after without sleeping', function () {
    sendFixture();
    sendIntent();
    fakeSend(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 120]], 429);
    processSend();
    test()->travel(60)->seconds();
    processSend();
    Http::assertSentCount(1);
    test()->travel(60)->seconds();
    processSend();
    Http::assertSentCount(2);
});
it('does not automatically repeat ambiguous transport results', function ($case) {
    sendFixture();
    sendIntent();
    $calls = 0;
    if ($case === 'timeout') {
        Http::fake(['*' => function () use (&$calls) {
            $calls++;
            throw new ConnectionException('credential must not leak');
        }]);
    } elseif ($case === 'malformed') {
        Http::fake(['*' => Http::response('bad', 502)]);
    } else {
        fakeSend(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => -999]]]);
    }
    processSend();
    test()->travel(120)->seconds();
    processSend();
    expect(TelegramDelivery::sole()->status)->toBe('INCIERTA')->and(TelegramDeliveryAttempt::count())->toBe(1);
    if ($case === 'timeout') {
        expect($calls)->toBe(1);
    } else {
        Http::assertSentCount(1);
    }
})->with(['timeout', 'malformed', 'wrong-chat']);
it('stops on a permanent Telegram rejection', function () {
    sendFixture();
    sendIntent();
    fakeSend(['ok' => false, 'error_code' => 403, 'description' => 'do not store me'], 403);
    processSend();
    test()->travel(60)->seconds();
    processSend();
    expect(TelegramDelivery::sole()->status)->toBe('ERROR_DEFINITIVO');
    expect(json_encode(TelegramDeliveryAttempt::sole()->toArray()))->not->toContain('do not store me');
    Http::assertSentCount(1);
});
it('revalidates pause changed destination and revoked recipient before sending', function ($change) {
    [$u,$b,$d] = sendFixture();
    sendIntent();
    if ($change === 'pause') {
        config(['telegram.sending_enabled' => false]);
    } elseif ($change === 'revision') {
        $d->increment('revision');
    } else {
        $u->forceFill(['is_active' => false])->save();
    }processSend();
    expect(TelegramDelivery::sole()->status)->toBeIn(['CANCELADA', 'OMITIDA'])->and(TelegramDeliveryAttempt::count())->toBe(0);
    Http::assertNothingSent();
})->with(['pause', 'revision', 'recipient']);
it('does not publish or retain an intent rolled back with its source transaction', function () {
    sendFixture();
    try {
        DB::transaction(function () {
            sendIntent();
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException $e) {
    }
    expect(TelegramDispatch::count())->toBe(0)->and(TelegramDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
it('recovers a missing queue job and prevents republishing it immediately', function () {
    sendFixture();
    sendIntent();
    $d = TelegramDelivery::sole();
    $d->update(['queued_at' => null]);
    $p = app(TelegramDeliveryPublisher::class);
    expect($p->due())->toBe(1)->and($p->due())->toBe(0);
    Queue::assertPushed(SendTelegramDelivery::class);
    Http::assertNothingSent();
});
it('marks abandoned processing uncertain instead of sending it again', function () {
    sendFixture();
    sendIntent();
    $d = TelegramDelivery::sole();
    $d->update(['status' => 'PROCESANDO', 'claim_token' => (string) Str::uuid(), 'lease_until' => now()->subSecond(), 'attempt_count' => 1]);
    TelegramDeliveryAttempt::create(['delivery_id' => $d->id, 'number' => 1, 'kind' => 'AUTO', 'status' => 'PROCESANDO', 'started_at' => now()->subMinutes(2)]);
    app(TelegramDeliveryPublisher::class)->due();
    expect($d->fresh()->status)->toBe('INCIERTA')->and(TelegramDeliveryAttempt::sole()->status)->toBe('INCIERTA');
    Http::assertNothingSent();
});
it('allows one audited manual retry without resetting automatic attempts', function () {
    [$u] = sendFixture();
    sendIntent();
    fakeSend(['ok' => false, 'error_code' => 500]);
    for ($i = 0; $i < 3; $i++) {
        processSend();
        test()->travel(60)->seconds();
    }
    $d = TelegramDelivery::sole();
    $v = ['delivery_id' => $d->id, 'expected_attempt_count' => $d->attempt_count, 'reason' => 'Revisión del fallo', 'request_key' => (string) Str::uuid(), 'confirm_uncertain' => false];
    $a = app(RetryTelegramDeliveryAction::class);
    $a->execute($u, $v);
    $a->execute($u, $v);
    processSend();
    expect($d->fresh()->status)->toBe('ERROR_DEFINITIVO')->and($d->fresh()->automatic_attempts)->toBe(3)->and($d->fresh()->attempt_count)->toBe(4);
    Http::assertSentCount(4);
    expect(TelegramConfigurationChange::where('kind', 'DELIVERY_RETRY')->count())->toBe(1);
});
it('requires confirmation to repeat an uncertain delivery and refuses retries while paused', function () {
    [$u] = sendFixture();
    sendIntent();
    Http::fake(['*' => Http::response('bad', 502)]);
    processSend();
    $d = TelegramDelivery::sole();
    $v = ['delivery_id' => $d->id, 'expected_attempt_count' => $d->attempt_count, 'reason' => 'Revisado', 'request_key' => (string) Str::uuid(), 'confirm_uncertain' => false];
    $a = app(RetryTelegramDeliveryAction::class);
    expect(fn () => $a->execute($u, $v))->toThrow(ValidationException::class);
    config(['telegram.sending_enabled' => false]);
    $v['confirm_uncertain'] = true;
    expect(fn () => $a->execute($u, $v))->toThrow(ValidationException::class);
});
it('protects delivery history and retries from cashiers and encargados', function ($role) {
    [$u] = sendFixture();
    sendIntent();
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $actor->givePermissionTo('notificaciones.telegram.configurar', 'panel.acceder');
    expect(fn () => app(TelegramDeliveryQuery::class)->history($actor))->toThrow(AuthorizationException::class);
    expect(fn () => app(RetryTelegramDeliveryAction::class)->execute($actor, []))->toThrow(AuthorizationException::class);
})->with(['cajera', 'encargado']);
it('blocks the HTTP client directly when paused', function () {
    sendFixture(false);
    expect(app(TelegramMessageClient::class)->send('ignored', '-1', 'test')['outcome'])->toBe('PAUSED');
    Http::assertNothingSent();
});

it('does not process the same delivery concurrently when a second worker enters during HTTP', function () {
    sendFixture();
    sendIntent();
    $calls = 0;
    Http::fake(['*' => function () use (&$calls) {
        $calls++;
        processSend();

        return Http::response(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => -100123]]]);
    }]);
    processSend();
    expect($calls)->toBe(1)->and(TelegramDeliveryAttempt::count())->toBe(1);
});
it('keeps durable pending data if publishing the queue fails and later recovers', function () {
    sendFixture();
    Queue::partialMock()->shouldReceive('connection')->with('telegram')->andThrow(new RuntimeException('publish unavailable'));
    sendIntent();
    $d = TelegramDelivery::sole();
    expect($d->status)->toBe('PENDIENTE')->and($d->queued_at)->toBeNull();
    Queue::fake();
    expect(app(TelegramDeliveryPublisher::class)->due())->toBe(1);
    Queue::assertPushed(SendTelegramDelivery::class);
    Http::assertNothingSent();
});
it('publishes only after the outer source transaction commits', function () {
    sendFixture();
    DB::transaction(function () {
        sendIntent();
        Queue::assertNothingPushed();
    });
    Queue::assertPushed(SendTelegramDelivery::class, 1);
    Http::assertNothingSent();
});
it('preserves uncertainty after a send succeeded but saving its response failed', function () {
    sendFixture();
    sendIntent();
    fakeSend(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => -100123]]]);
    DB::statement("CREATE TRIGGER fail_delivery_success BEFORE UPDATE ON telegram_deliveries WHEN NEW.status = 'ENVIADA' BEGIN SELECT RAISE(ABORT, 'test'); END");
    $job = new SendTelegramDelivery(TelegramDelivery::sole()->id);
    try {
        $job->handle(app(ProcessTelegramDeliveryAction::class));
    } catch (Throwable $e) {
        $job->failed($e);
    }processSend();
    expect(TelegramDelivery::sole()->status)->toBe('INCIERTA');
    Http::assertSentCount(1);
});
it('does not let an old failed callback cancel another claim', function () {
    sendFixture();
    sendIntent();
    $job = new SendTelegramDelivery(TelegramDelivery::sole()->id);
    $d = TelegramDelivery::sole();
    $d->update(['status' => 'PROCESANDO', 'claim_token' => (string) Str::uuid(), 'lease_until' => now()->addSeconds(90)]);
    $job->failed(new RuntimeException('old worker'));
    expect($d->fresh()->status)->toBe('PROCESANDO');
    Http::assertNothingSent();
});
it('omits unprepared disabled or unverified destinations before queueing', function ($change) {
    [$u,$b,$d,$e] = sendFixture();
    if ($change === 'event') {
        $e->update(['prepared' => false]);
    } elseif ($change === 'bot') {
        $b->update(['is_enabled' => false]);
    } elseif ($change === 'destination') {
        $d->update(['is_enabled' => false]);
    } else {
        TelegramConfigurationChange::where('kind', 'DESTINATION_CHECK')->delete();
    }
    sendIntent();
    expect(TelegramDispatch::sole()->status)->toBe('OMITIDA')->and(TelegramDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['event', 'bot', 'destination', 'check']);
it('delivers once per explicitly configured distinct chat', function () {
    [$u,$b,$d,$e] = sendFixture();
    $second = TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $u->id, 'name' => 'Second', 'chat_id' => '-100456', 'kind' => 'GROUP', 'is_enabled' => true, 'revision' => 1, 'updated_by' => $u->id]);
    $e->destinations()->attach($second->id, ['destination_revision' => 1]);
    TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'DESTINATION_CHECK', 'source_id' => $second->id, 'snapshot' => ['after' => ['revision' => 1], 'bot_revision' => 1, 'status' => 'CONNECTED'], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'second'), 'created_at' => now()]);
    sendIntent();
    sendIntent();
    expect(TelegramDelivery::count())->toBe(2);
    Http::fake(['*' => fn ($r) => Http::response(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => (int) $r['chat_id']]]])]);
    foreach (TelegramDelivery::all() as $delivery) {
        app(ProcessTelegramDeliveryAction::class)->execute($delivery->id);
    }Http::assertSentCount(2);
    expect(TelegramDelivery::where('status', 'ENVIADA')->count())->toBe(2);
});
it('requires fresh authorization for retry and history even for administrators', function ($case) {
    [$u] = sendFixture();
    sendIntent();
    if ($case === 'inactive') {
        $u->forceFill(['is_active' => false])->save();
    } else {
        $u->restrictedPermissions()->attach(Permission::findByName('notificaciones.telegram.configurar')->id);
    }
    expect(fn () => app(TelegramDeliveryQuery::class)->history($u))->toThrow(AuthorizationException::class);
    expect(fn () => app(RetryTelegramDeliveryAction::class)->execute($u, []))->toThrow(AuthorizationException::class);
})->with(['inactive', 'restriction']);
it('allows an explicitly confirmed uncertain manual retry but never repeats a sent message', function () {
    [$u] = sendFixture();
    sendIntent();
    Http::fake(['*' => Http::sequence()->push('bad', 502)->push(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => -100123]]])]);
    processSend();
    $d = TelegramDelivery::sole();
    $v = ['delivery_id' => $d->id, 'expected_attempt_count' => $d->attempt_count, 'reason' => 'Chat revisado', 'confirm_uncertain' => true, 'request_key' => (string) Str::uuid()];
    app(RetryTelegramDeliveryAction::class)->execute($u, $v);
    processSend();
    expect($d->fresh()->status)->toBe('ENVIADA')->and($d->fresh()->automatic_attempts)->toBe(1)->and($d->fresh()->attempt_count)->toBe(2);
    $v['expected_attempt_count'] = 2;
    $v['request_key'] = (string) Str::uuid();
    expect(fn () => app(RetryTelegramDeliveryAction::class)->execute($u, $v))->toThrow(ValidationException::class);
    Http::assertSentCount(2);
});
it('keeps credentials and Telegram descriptions out of persisted error details', function () {
    [$u,$b] = sendFixture();
    sendIntent();
    fakeSend(['ok' => false, 'error_code' => 403, 'description' => 'secret '.$b->token], 403);
    processSend();
    expect(json_encode(TelegramDeliveryAttempt::sole()->toArray()))->not->toContain($b->token);
    expect(json_encode(TelegramDelivery::sole()->toArray()))->not->toContain($b->token)->not->toContain('-100123');
});
it('paginates history and preserves module and status filters without networking', function () {
    [$u] = sendFixture(false);
    for ($i = 0; $i < 7; $i++) {
        sendIntent('close:'.$i.':registered');
    }$q = app(TelegramDeliveryQuery::class);
    $page = $q->history($u, 'CAJAS', 'OMITIDA');
    expect($page->total())->toBe(7)->and($page->count())->toBe(5);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('inicio'));
    Livewire\Livewire::test(TelegramDeliveryHistory::class)->assertSee('close:6:registered')->assertDontSee('close:0:registered')->call('setPage', 2)->assertSee('close:0:registered')->set('module', 'ACCESORIOS')->assertSee('Todavía no hay avisos registrados');
    Http::assertNothingSent();
});
it('renders escaped message details and a disabled manual retry while paused', function () {
    [$u] = sendFixture();
    $dispatch = app(QueueTelegramNoticeAction::class)->execute('CAJAS', 'CJ_CIERRE', 'close:script:test', '<script>alert(1)</script>');
    fakeSend(['ok' => false, 'error_code' => 403], 403);
    processSend();
    config(['telegram.sending_enabled' => false]);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('inicio'));
    Livewire\Livewire::test(TelegramDeliveryHistory::class)->mountAction('detail', ['id' => $dispatch->id])->assertActionMounted('detail')->assertMountedActionModalSee('&lt;script&gt;', false)->assertMountedActionModalSee('Error definitivo');
    Http::assertSentCount(1);
});
it('rejects unknown history filters and preserves failed attempts from being rewritten', function () {
    [$u] = sendFixture();
    sendIntent();
    fakeSend(['ok' => false, 'error_code' => 403], 403);
    processSend();
    expect(fn () => app(TelegramDeliveryQuery::class)->history($u, 'NOT_A_MODULE'))->toThrow(ValidationException::class);
    expect(fn () => TelegramDeliveryAttempt::sole()->update(['status' => 'ENVIADA']))->toThrow(LogicException::class);
});

it('requests a manual retry through the administrative modal and keeps its audit visible', function () {
    [$u] = sendFixture();
    sendIntent();
    fakeSend(['ok' => false, 'error_code' => 403], 403);
    processSend();
    $delivery = TelegramDelivery::sole();
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('inicio'));
    Livewire\Livewire::test(TelegramDeliveryHistory::class)->mountAction('retry', ['id' => $delivery->id])->setActionData(['reason' => 'Permisos revisados'])->callMountedAction()->assertHasNoActionErrors();
    expect($delivery->fresh()->manual_pending)->toBeTrue()->and($delivery->fresh()->status)->toBe('PENDIENTE');
    Livewire\Livewire::test(TelegramSettings::class)->assertSee('Reintento de entrega');
    Http::assertSentCount(1);
});
it('rolls back a manual retry if audit writing fails and rejects a conflicting request key', function () {
    [$u] = sendFixture();
    sendIntent();
    fakeSend(['ok' => false, 'error_code' => 403], 403);
    processSend();
    $d = TelegramDelivery::sole();
    $v = ['delivery_id' => $d->id, 'expected_attempt_count' => 1, 'reason' => 'Permisos revisados', 'confirm_uncertain' => false, 'request_key' => (string) Str::uuid()];
    DB::statement("CREATE TRIGGER fail_retry_audit BEFORE INSERT ON telegram_configuration_changes WHEN NEW.kind = 'DELIVERY_RETRY' BEGIN SELECT RAISE(ABORT, 'test'); END");
    expect(fn () => app(RetryTelegramDeliveryAction::class)->execute($u, $v))->toThrow(QueryException::class);
    expect($d->fresh()->status)->toBe('ERROR_DEFINITIVO')->and($d->fresh()->manual_pending)->toBeFalse();
    DB::statement('DROP TRIGGER fail_retry_audit');
    $a = app(RetryTelegramDeliveryAction::class);
    $a->execute($u, $v);
    expect(fn () => $a->execute($u, array_replace($v, ['reason' => 'Otro motivo'])))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('administrador');
    expect(fn () => $a->execute($other, $v))->toThrow(AuthorizationException::class);
    Http::assertSentCount(1);
});
it('keeps all attempts accessible through pagination in the detail modal', function () {
    [$u] = sendFixture();
    $dispatch = sendIntent();
    $d = TelegramDelivery::sole();
    $d->update(['status' => 'ERROR_DEFINITIVO', 'attempt_count' => 12]);
    for ($n = 1; $n <= 12; $n++) {
        TelegramDeliveryAttempt::create(['delivery_id' => $d->id, 'number' => $n, 'kind' => 'MANUAL', 'status' => 'ERROR_DEFINITIVO', 'code' => 'API_REJECTED', 'started_at' => now(), 'finished_at' => now()]);
    }
    $detail = app(TelegramDeliveryQuery::class)->detail($u, $dispatch->id);
    expect($detail['attempts']->total())->toBe(12)->and($detail['attempts']->count())->toBe(10);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('inicio'));
    Livewire\Livewire::test(TelegramDeliveryHistory::class)->mountAction('detail', ['id' => $dispatch->id])->assertMountedActionModalSee('#'.$d->id.' · 12')->call('setPage', 2, 'attemptsPage')->assertMountedActionModalSee('#'.$d->id.' · 2');
    Http::assertNothingSent();
});
