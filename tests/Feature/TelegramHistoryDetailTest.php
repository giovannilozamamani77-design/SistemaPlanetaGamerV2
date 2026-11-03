<?php

use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDeliveryAttempt;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function detailFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    Http::preventStrayRequests();
    Queue::fake();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);
    $b = TelegramBotSetting::create(['module' => 'CAJAS', 'name' => 'Nombre actual del bot', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', 'detail'), 'revision' => 1, 'updated_by' => $u->id]);
    $d = TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $u->id, 'name' => 'Destino actual', 'chat_id' => '-100123', 'kind' => 'GROUP', 'revision' => 1, 'updated_by' => $u->id]);

    return [$u, $b, $d];
}
function detailAudit($u, string $kind, int $source, array $snapshot)
{
    return TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => $kind, 'source_id' => $source, 'snapshot' => $snapshot, 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'created_at' => now()]);
}
function detailTest($u, $b, $d, bool $legacy = false, ?string $status = 'SENT')
{
    $s = ['bot_id' => $b->id, 'destination_id' => $d->id, 'module' => 'CAJAS', 'bot_name' => 'Bot histórico', 'destination_name' => 'Destino histórico', 'status' => 'PENDING'];
    if (! $legacy) {
        $s += ['message' => 'PRUEBA guardada exactamente', 'message_template_version' => 1];
    }
    $r = detailAudit($u, 'BOT_TEST_REQUEST', $b->id, $s);
    if (! $legacy) {
        detailAudit($u, 'BOT_TEST_ATTEMPT', $r->id, ['status' => 'STARTED', 'bot_id' => $b->id, 'destination_id' => $d->id]);
    }
    if ($status) {
        detailAudit($u, 'BOT_TEST_RESULT', $r->id, ['status' => $status, 'code' => $status === 'SENT' ? 'SENT' : 'TRANSPORT_UNCERTAIN', 'message_id' => $status === 'SENT' ? '77' : null, 'http_status' => $status === 'SENT' ? 200 : null]);
    }

    return $r;
}
it('opens a manual test detail with its saved message destination and one recorded attempt without side effects', function () {
    [$u,$b,$d] = detailFixture();
    $r = detailTest($u, $b, $d);
    $before = DB::table('telegram_configuration_changes')->orderBy('id')->get()->toJson();
    $detail = app(TelegramDeliveryQuery::class)->testDetail($u, $r->id);
    expect($detail['message'])->toBe('PRUEBA guardada exactamente')->and($detail['destination_name'])->toBe('Destino histórico')->and($detail['attempts_recorded'])->toBe(1)->and($detail['status'])->toBe('SENT');
    Livewire::test(TelegramDeliveryHistory::class)->set('type', 'TEST')->mountAction('testDetail', ['id' => $r->id])->assertMountedActionModalSee('Mensaje de prueba')->assertMountedActionModalSee('PRUEBA guardada exactamente')->assertMountedActionModalSee('Destino histórico')->assertMountedActionModalSee('Intento manual')->assertMountedActionModalDontSee('Reintentar')->assertMountedActionModalDontSee($b->token)->assertMountedActionModalDontSee($d->chat_id);
    expect(DB::table('telegram_configuration_changes')->orderBy('id')->get()->toJson())->toBe($before);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
it('does not invent a saved message or an attempt start for a legacy test', function () {
    [$u,$b,$d] = detailFixture();
    $r = detailTest($u, $b, $d, true);
    $data = app(TelegramDeliveryQuery::class)->testDetail($u, $r->id);
    expect($data['message'])->toBeNull()->and($data['attempts_recorded'])->toBe(0)->and($data['legacy_attempt'])->toBeTrue();
    Livewire::test(TelegramDeliveryHistory::class)->mountAction('testDetail', ['id' => $r->id])->assertMountedActionModalSee('No se guardó el texto original')->assertMountedActionModalSee('No se conservó el inicio del intento');
    Http::assertNothingSent();
});
it('shows a pending or uncertain test without suggesting an automatic resend', function ($status) {
    [$u,$b,$d] = detailFixture();
    $r = detailTest($u, $b, $d, false, $status);
    Livewire::test(TelegramDeliveryHistory::class)->mountAction('testDetail', ['id' => $r->id])->assertMountedActionModalSee('Revisa Telegram')->assertMountedActionModalSee('Sin reintentos automáticos')->assertMountedActionModalDontSee('Reintentar');
    Http::assertNothingSent();
})->with([null, 'UNCERTAIN']);
it('rejects result IDs and enforces the same role permissions on both detail sources', function ($role) {
    [$u,$b,$d] = detailFixture();
    $r = detailTest($u, $b, $d);
    $q = app(TelegramDeliveryQuery::class);
    expect(fn () => $q->testDetail($u, TelegramConfigurationChange::where('kind', 'BOT_TEST_RESULT')->sole()->id))->toThrow(ModelNotFoundException::class);
    if ($role === 'inactive') {
        $u->forceFill(['is_active' => false])->save();
    } else {
        $u->syncRoles([$role]);
        $u->givePermissionTo('notificaciones.telegram.configurar');
    }
    expect(fn () => $q->testDetail($u, $r->id))->toThrow(AuthorizationException::class);
    expect(fn () => $q->detail($u, 1))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
})->with(['cajera', 'encargado', 'inactive']);
it('labels automatic detail with saved bot and destination identities and destination linked attempts', function () {
    [$u,$b,$d] = detailFixture();
    $e = TelegramEventSetting::create(['bot_id' => $b->id, 'event_code' => 'CJ_CIERRE', 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $u->id]);
    $r = TelegramDispatch::create(['event_key' => hash('sha256', 'auto-detail'), 'module' => 'CAJAS', 'event_code' => 'CJ_CIERRE', 'source_reference' => 'close:detail', 'payload_hash' => hash('sha256', 'payload'), 'status' => 'EN_COLA', 'message' => 'Texto automático guardado']);
    $delivery = TelegramDelivery::create(['dispatch_id' => $r->id, 'bot_id' => $b->id, 'destination_id' => $d->id, 'event_setting_id' => $e->id, 'destination_name' => 'Destino histórico automático', 'chat_id' => $d->chat_id, 'chat_fingerprint' => hash('sha256', 'chat'), 'snapshot' => ['bot_name' => 'Bot histórico automático'], 'status' => 'ENVIADA', 'attempt_count' => 1, 'automatic_attempts' => 1]);
    TelegramDeliveryAttempt::create(['delivery_id' => $delivery->id, 'number' => 1, 'kind' => 'AUTO', 'status' => 'ENVIADA', 'code' => 'SENT', 'started_at' => now(), 'finished_at' => now()]);
    $data = app(TelegramDeliveryQuery::class)->detail($u, $r->id);
    expect($data['bot_name'])->toBe('Bot histórico automático')->and($data['attempt_destinations'][$delivery->id])->toBe('Destino histórico automático');
    Livewire::test(TelegramDeliveryHistory::class)->mountAction('detail', ['id' => $r->id])->assertMountedActionModalSee('Aviso automático')->assertMountedActionModalSee('Texto automático guardado')->assertMountedActionModalSee('Bot histórico automático')->assertMountedActionModalSee('Destino histórico automático');
    Http::assertNothingSent();
});
it('escapes saved test content while preserving filters and page after closing detail', function () {
    [$u,$b,$d] = detailFixture();
    $r = detailTest($u, $b, $d);
    $s = $r->snapshot;
    $s['message'] = '<script>unsafe</script>';
    DB::table('telegram_configuration_changes')->where('id', $r->id)->update(['snapshot' => json_encode($s)]);
    for ($i = 0; $i < 7; $i++) {
        detailTest($u, $b, $d);
    }
    $view = Livewire::test(TelegramDeliveryHistory::class)->set('type', 'TEST')->set('module', 'CAJAS')->set('destinationId', (string) $d->id)->call('setPage', 2)->mountAction('testDetail', ['id' => $r->id])->assertMountedActionModalSee('&lt;script&gt;unsafe&lt;/script&gt;', false)->assertMountedActionModalDontSee('<script>unsafe</script>', false)->unmountAction()->assertSet('type', 'TEST')->assertSet('module', 'CAJAS')->assertSet('destinationId', (string) $d->id);
    expect($view->get('paginators.page'))->toBe(2);
    Http::assertNothingSent();
});

it('explains omitted automatic records without inventing a message destination or attempt', function () {
    [$u,$b,$d] = detailFixture();
    $r = TelegramDispatch::create(['event_key' => hash('sha256', 'omitted-detail'), 'module' => 'CAJAS', 'event_code' => 'CJ_CIERRE', 'source_reference' => 'close:omitted', 'payload_hash' => hash('sha256', 'payload'), 'status' => 'OMITIDA', 'reason' => 'PAUSED']);
    Livewire::test(TelegramDeliveryHistory::class)->mountAction('detail', ['id' => $r->id])->assertMountedActionModalSee('Aviso automático')->assertMountedActionModalSee('Aviso omitido')->assertMountedActionModalSee('Sin intentos de envío');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
