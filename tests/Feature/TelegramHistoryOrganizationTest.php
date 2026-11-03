<?php

use App\Domain\Notificaciones\Actions\QueueTelegramNoticeAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Queries\TelegramDeliveryQuery;
use App\Filament\Pages\Notificaciones\TelegramDeliveryHistory;
use App\Models\User;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function historyFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    Http::preventStrayRequests();
    Queue::fake();
    test()->freezeTime();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);
    $b = TelegramBotSetting::create(['module' => 'CAJAS', 'name' => 'Bot Cajas actual', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', 'history'), 'revision' => 1, 'updated_by' => $u->id]);
    $d = TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $u->id, 'name' => 'Destino actual', 'chat_id' => '-100123', 'kind' => 'GROUP', 'revision' => 1, 'updated_by' => $u->id]);

    return [$u, $b, $d];
}
function historyTestRequest($u, $b, $d, ?string $status = 'SENT', bool $legacy = false)
{
    $snapshot = ['status' => 'PENDING', 'bot_id' => $b->id, 'destination_id' => $d->id];
    if (! $legacy) {
        $snapshot += ['module' => 'CAJAS', 'bot_name' => 'Bot al enviar', 'destination_name' => 'Destino al enviar'];
    }
    $r = TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'BOT_TEST_REQUEST', 'source_id' => $b->id, 'snapshot' => $snapshot, 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'created_at' => now()]);
    if ($status) {
        TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'BOT_TEST_RESULT', 'source_id' => $r->id, 'snapshot' => ['status' => $status, 'bot_id' => $b->id, 'destination_id' => $d->id, 'code' => $status === 'SENT' ? 'SENT' : 'UNCONFIRMED_RESPONSE'], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'result'), 'created_at' => now()]);
    }

    return $r;
}
function historyAutomatic(int $i = 1)
{
    return TelegramDispatch::create(['event_key' => hash('sha256', 'auto'.$i), 'module' => 'CAJAS', 'event_code' => 'CJ_CIERRE', 'source_reference' => 'close:'.$i.':registered', 'payload_hash' => hash('sha256', 'payload'), 'status' => 'OMITIDA', 'reason' => 'PAUSED']);
}
it('shows manual requests once with their result and preserves five row pagination', function () {
    [$u,$b,$d] = historyFixture();
    $ids = [];
    for ($i = 0; $i < 7; $i++) {
        $ids[] = historyTestRequest($u, $b, $d)->id;
    }
    $q = app(TelegramDeliveryQuery::class);
    $page = $q->testHistory($u);
    expect($page->total())->toBe(7)->and($page->perPage())->toBe(5)->and($page->items()[0]['id'])->toBe($ids[6]);
    expect($page->items()[0])->toMatchArray(['bot_name' => 'Bot al enviar', 'destination_name' => 'Destino al enviar', 'status' => 'SENT']);
    $view = Livewire::test(TelegramDeliveryHistory::class)->set('type', 'TEST')->assertSee('Mensajes de prueba')->assertSee('Bot al enviar')->assertSee('Destino al enviar')->assertSee('Prueba enviada');
    $view->call('setPage', 2)->assertSee('Prueba #'.$ids[0]);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
it('keeps automatic and test lists separate and resets page and incompatible status on tab change', function () {
    [$u,$b,$d] = historyFixture();
    historyTestRequest($u, $b, $d);
    for ($i = 0; $i < 7; $i++) {
        historyAutomatic($i);
    }
    $view = Livewire::test(TelegramDeliveryHistory::class)->assertSee('Bot Cajas actual')->assertSee('close:6:registered')->assertDontSee('Destino al enviar');
    $view->call('setPage', 2)->assertSee('close:0:registered')->set('status', 'OMITIDA')->set('type', 'TEST')->assertSet('status', '')->assertSee('Destino al enviar')->assertDontSee('close:0:registered');
    expect(app(TelegramDeliveryQuery::class)->history($u)->total())->toBe(7);
    Http::assertNothingSent();
});
it('filters test results and modules without counting result audits as another message', function () {
    [$u,$b,$d] = historyFixture();
    historyTestRequest($u, $b, $d, 'SENT');
    historyTestRequest($u, $b, $d, 'REJECTED');
    historyTestRequest($u, $b, $d, null);
    $q = app(TelegramDeliveryQuery::class);
    expect($q->testHistory($u, 'CAJAS', 'SENT')->total())->toBe(1)->and($q->testHistory($u, 'CAJAS', 'PENDING')->total())->toBe(1)->and($q->testHistory($u, 'PRESTAMOS')->total())->toBe(0);
    Livewire::test(TelegramDeliveryHistory::class)->set('type', 'TEST')->set('status', 'REJECTED')->assertSee('Telegram rechazó la prueba')->assertDontSee('Prueba enviada');
    expect(fn () => $q->testHistory($u, 'BOGUS'))->toThrow(ValidationException::class);
    expect(fn () => $q->testHistory($u, '', 'OMITIDA'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});
it('labels current fallback names for legacy records instead of inventing historical names', function () {
    [$u,$b,$d] = historyFixture();
    historyTestRequest($u, $b, $d, 'UNCERTAIN', true);
    $row = app(TelegramDeliveryQuery::class)->testHistory($u)->items()[0];
    expect($row)->toMatchArray(['bot_name' => 'Bot Cajas actual', 'destination_name' => 'Destino actual', 'bot_name_historical' => false, 'destination_name_historical' => false]);
    Livewire::test(TelegramDeliveryHistory::class)->set('type', 'TEST')->assertSee('Nombre actual')->assertSee('Resultado sin confirmar')->assertDontSee($b->token)->assertDontSee($d->chat_id);
    Http::assertNothingSent();
});
it('forbids unauthorized history readers including cashiers managers and inactive administrators', function ($role) {
    [$u,$b,$d] = historyFixture();
    if ($role === 'inactive') {
        $u->forceFill(['is_active' => false])->save();
    } else {
        $u->syncRoles([$role]);
        $u->givePermissionTo('notificaciones.telegram.configurar');
    }
    expect(fn () => app(TelegramDeliveryQuery::class)->testHistory($u))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
})->with(['cajera', 'encargado', 'inactive']);
it('renders escaped names and never queues or changes a record while reading', function () {
    [$u,$b,$d] = historyFixture();
    $r = historyTestRequest($u, $b, $d);
    $snapshot = $r->snapshot;
    $snapshot['bot_name'] = '<script>bad</script>';
    DB::table('telegram_configuration_changes')->where('id', $r->id)->update(['snapshot' => json_encode($snapshot)]);
    $before = DB::table('telegram_configuration_changes')->orderBy('id')->get()->toJson();
    Livewire::test(TelegramDeliveryHistory::class)->set('type', 'TEST')->assertSee('&lt;script&gt;bad&lt;/script&gt;', false)->assertDontSee('<script>bad</script>', false);
    expect(DB::table('telegram_configuration_changes')->orderBy('id')->get()->toJson())->toBe($before);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('preserves the bot name frozen in a new automatic delivery after configuration is renamed', function () {
    [$u,$b,$d] = historyFixture();
    $b->update(['is_enabled' => true]);
    $d->update(['is_enabled' => true]);
    $event = TelegramEventSetting::create(['bot_id' => $b->id, 'event_code' => 'CJ_CIERRE', 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $u->id]);
    $event->destinations()->attach($d->id, ['destination_revision' => 1]);
    foreach (['BOT_CHECK' => $b->id, 'DESTINATION_CHECK' => $d->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['status' => 'CONNECTED', 'after' => ['revision' => 1], 'bot_revision' => 1], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }
    app(QueueTelegramNoticeAction::class)->execute('CAJAS', 'CJ_CIERRE', 'close:frozen', 'Texto simulado');
    $b->update(['name' => 'Nombre cambiado']);
    $d->update(['name' => 'Destino cambiado']);
    $row = app(TelegramDeliveryQuery::class)->history($u)->items()[0];
    expect($row->bot_name)->toBe('Bot Cajas actual')->and($row->bot_name_historical)->toBeTrue()->and($row->deliveries->first()->destination_name)->toBe('Destino actual');
    Http::assertNothingSent();
});
it('loads test history in bounded bulk queries instead of querying each row', function () {
    [$u,$b,$d] = historyFixture();
    historyTestRequest($u, $b, $d);
    $q = app(TelegramDeliveryQuery::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $q->testHistory($u);
    $small = count(DB::getQueryLog());
    DB::disableQueryLog();
    for ($i = 0; $i < 6; $i++) {
        historyTestRequest($u, $b, $d);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $page = $q->testHistory($u);
    $large = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($page->count())->toBe(5)->and($large)->toBeLessThanOrEqual($small + 1);
    Http::assertNothingSent();
});

it('marks missing legacy names as unavailable without treating them as current identities', function () {
    [$u,$b,$d] = historyFixture();
    $r = historyTestRequest($u, $b, $d, null, true);
    DB::table('telegram_configuration_changes')->where('id', $r->id)->update(['source_id' => 99999, 'snapshot' => json_encode(['status' => 'PENDING', 'bot_id' => 99999, 'destination_id' => 99999])]);
    $row = app(TelegramDeliveryQuery::class)->testHistory($u)->items()[0];
    expect($row)->toMatchArray(['bot_name' => 'Bot no disponible', 'destination_name' => 'Destino no disponible', 'bot_name_note' => 'Nombre no guardado', 'destination_name_note' => 'Nombre no guardado']);
    Livewire::test(TelegramDeliveryHistory::class)->set('type', 'TEST')->assertSee('Nombre no guardado')->assertSee('sin resultado confirmado');
    Http::assertNothingSent();
});
