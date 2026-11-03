<?php

use App\Domain\Notificaciones\Actions\QueueTelegramNoticeAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Models\User;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->artisan('migrate:fresh')->assertExitCode(0);
});
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});
it('uses the dedicated database queue with an ID-only payload and runs a simulated job', function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->freezeTime();
    Http::preventStrayRequests();
    config(['telegram.sending_enabled' => true]);
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    $u = User::factory()->create();
    $u->assignRole('administrador');
    $b = TelegramBotSetting::create(['module' => 'CAJAS', 'name' => 'Test', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', 'test'), 'is_enabled' => true, 'revision' => 1, 'updated_by' => $u->id]);
    $d = TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $u->id, 'name' => 'Test', 'chat_id' => '-100123', 'kind' => 'GROUP', 'is_enabled' => true, 'revision' => 1, 'updated_by' => $u->id]);
    $e = TelegramEventSetting::create(['bot_id' => $b->id, 'event_code' => 'CJ_CIERRE', 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $u->id]);
    $e->destinations()->attach($d->id, ['destination_revision' => 1]);
    foreach (['BOT_CHECK' => $b->id, 'DESTINATION_CHECK' => $d->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['after' => ['revision' => 1], 'bot_revision' => 1, 'status' => 'CONNECTED'], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }
    app(QueueTelegramNoticeAction::class)->execute('CAJAS', 'CJ_CIERRE', 'close:123:registered', 'CIERRE SIMULADO · Bs 30');
    expect(DB::table('jobs')->sole()->queue)->toBe('telegram');
    $payload = DB::table('jobs')->value('payload');
    expect($payload)->not->toContain($b->token)->not->toContain('CIERRE SIMULADO');
    Http::fake(['https://api.telegram.org/bot123456:'.str_repeat('A', 35).'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 99, 'chat' => ['id' => -100123]]])]);
    $job = Queue::connection('telegram')->pop('telegram');
    expect($job)->not->toBeNull();
    $job->fire();
    $job->delete();
    expect(TelegramDelivery::sole()->status)->toBe('ENVIADA')->and(DB::table('jobs')->count())->toBe(0);
    Http::assertSentCount(1);
});
