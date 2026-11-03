<?php

use App\Domain\Notificaciones\Actions\QueueTelegramNoticeAction;
use App\Domain\Notificaciones\Actions\SaveTelegramBotAction;
use App\Domain\Notificaciones\Actions\SaveTelegramDestinationAction;
use App\Domain\Notificaciones\Actions\SaveTelegramEventAction;
use App\Domain\Notificaciones\Actions\SetTelegramBotStateAction;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Queries\TelegramEventsQuery;
use App\Domain\Notificaciones\Services\TelegramDeliveryGuard;
use App\Models\User;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Artisan::call('migrate:fresh', ['--force' => true]);
});
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});
function readinessFixture(): array
{
    test()->freezeTime();
    Http::preventStrayRequests();
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    config(['telegram.sending_enabled' => true, 'cache.default' => 'array']);
    Cache::setDefaultDriver('array');
    $u = User::factory()->create();
    $u->assignRole('administrador');
    $b = app(SaveTelegramBotAction::class)->execute($u, ['module' => 'CAJAS', 'name' => 'Bot simulado', 'token' => '123456:'.str_repeat('A', 35), 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $d = app(SaveTelegramDestinationAction::class)->execute($u, ['bot_id' => $b->id, 'responsible_id' => $u->id, 'name' => 'Giovanni simulado', 'kind' => 'PRIVATE', 'chat_id' => '12345', 'private_confirmed' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    foreach (['BOT_CHECK' => $b->id, 'DESTINATION_CHECK' => $d->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['status' => 'CONNECTED', 'after' => ['revision' => 1], 'bot_revision' => 1], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }
    foreach (['CJ_APERTURA', 'CJ_CIERRE'] as $code) {
        $f = app(TelegramEventsQuery::class)->form($u, 'CAJAS', $code);
        $f['prepared'] = true;
        $f['destination_ids'] = [$d->id];
        $a = app(SaveTelegramEventAction::class);
        $a->execute($u, $f);
        $a->execute($u, $f);
    }

    return [$u, $b, $d];
}
it('prepares only opening and closing for the verified recipient without activating notices or duplicating configuration', function () {
    [$u,$b,$d] = readinessFixture();
    expect(TelegramEventSetting::orderBy('event_code')->pluck('event_code')->all())->toBe(['CJ_APERTURA', 'CJ_CIERRE']);
    expect(TelegramConfigurationChange::where('kind', 'EVENT_SETTING')->count())->toBe(2)->and($b->fresh()->is_enabled)->toBeFalse()->and($d->fresh()->is_enabled)->toBeFalse();
    foreach (TelegramEventSetting::all() as $event) {
        expect($event->prepared)->toBeTrue()->and($event->destinations->pluck('id')->all())->toBe([$d->id])->and(app(TelegramDeliveryGuard::class)->reason($event, $d, true))->toBeNull();
    }
    expect(DB::table('cash_box_sessions')->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
    Http::assertNothingSent();
});
it('recovers scheduled due work and runs both simulated deliveries once using the dedicated database queue', function () {
    [$u,$b,$d] = readinessFixture();
    app(SetTelegramBotStateAction::class)->execute($u, ['bot_id' => $b->id, 'enabled' => true, 'expected_revision' => 1, 'expected_state_version' => 0, 'destination_ids' => [$d->id], 'destination_revisions' => [$d->id => 1], 'request_key' => (string) Str::uuid()]);
    foreach (['CJ_APERTURA', 'CJ_CIERRE'] as $code) {
        app(QueueTelegramNoticeAction::class)->execute('CAJAS', $code, 'simulated:'.$code, 'Aviso simulado '.$code);
    }
    expect(DB::table('jobs')->count())->toBe(2);
    // Simulate lost publication in this asserted in-memory database only.
    DB::table('jobs')->delete();
    TelegramDelivery::query()->update(['queued_at' => null]);
    $scheduled = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'telegram:dispatch-due'));
    expect($scheduled)->toHaveCount(1);
    $event = $scheduled->first();
    expect($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->filtersPass(app()))->toBeTrue();
    Artisan::call('telegram:dispatch-due');
    Artisan::call('telegram:dispatch-due');
    expect(DB::table('jobs')->count())->toBe(2);
    $calls = 0;
    Http::fake(['https://api.telegram.org/bot'.$b->token.'/sendMessage' => function ($request) use (&$calls) {
        expect(DB::transactionLevel())->toBe(0)->and($request['chat_id'])->toBe('12345');

        return Http::response(['ok' => true, 'result' => ['message_id' => ++$calls, 'chat' => ['id' => 12345]]]);
    }]);
    for ($i = 0; $i < 2; $i++) {
        $job = Queue::connection('telegram')->pop('telegram');
        expect($job)->not->toBeNull();
        $job->fire();
        $job->fire();
        $job->delete();
    }
    Artisan::call('telegram:dispatch-due');
    expect(TelegramDelivery::where('status', 'ENVIADA')->count())->toBe(2)->and(DB::table('jobs')->count())->toBe(0)->and(TelegramDispatch::count())->toBe(2)->and(DB::table('cash_box_sessions')->count())->toBe(0);
    Http::assertSentCount(2);
});
it('leaves prepared bots paused and scheduler maintenance guarded without sending backlog', function () {
    [$u,$b,$d] = readinessFixture();
    foreach (['CJ_APERTURA', 'CJ_CIERRE'] as $code) {
        app(QueueTelegramNoticeAction::class)->execute('CAJAS', $code, 'paused:'.$code, 'Simulado '.$code);
    }
    Artisan::call('telegram:dispatch-due');
    expect(TelegramDispatch::where('status', 'OMITIDA')->count())->toBe(2)->and(TelegramDelivery::count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
    config(['telegram.sending_enabled' => false]);
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'telegram:dispatch-due'));
    expect($event->filtersPass(app()))->toBeFalse();
    Http::assertNothingSent();
});
