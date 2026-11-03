<?php

use App\Domain\Notificaciones\Actions\SaveTelegramTemplateAction;
use App\Domain\Notificaciones\Actions\SendTelegramTestMessageAction;
use App\Domain\Notificaciones\Data\TelegramEventCatalog;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Queries\TelegramDeliveryQuery;
use App\Domain\Notificaciones\Queries\TelegramTemplateQuery;
use App\Filament\Pages\Notificaciones\TelegramEvents;
use App\Models\User;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Artisan::call('migrate:fresh', ['--force' => true]);
});
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});
function templateSendFixture(string $module = 'CAJAS', string $code = 'CJ_APERTURA'): array
{
    test()->freezeTime();
    config(['telegram.sending_enabled' => true]);
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    Http::preventStrayRequests();
    Queue::fake();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);
    $b = TelegramBotSetting::create(['module' => $module, 'name' => 'Bot de prueba', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', 'fake'), 'revision' => 1, 'is_enabled' => false, 'updated_by' => $u->id]);
    $d = TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $u->id, 'name' => 'Destino de prueba', 'chat_id' => '-100123', 'kind' => 'GROUP', 'is_enabled' => false, 'revision' => 1, 'updated_by' => $u->id]);
    foreach (['BOT_CHECK' => $b->id, 'DESTINATION_CHECK' => $d->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['status' => 'CONNECTED', 'after' => ['revision' => 1], 'bot_revision' => 1], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }
    $image = UploadedFile::fake()->createWithContent('prueba.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII='));
    $template = app(SaveTelegramTemplateAction::class)->execute($u, ['module' => $module, 'event_code' => $code, 'body' => "Mensaje de {{modulo}}\n{{detalle}}", 'image_mode' => 'CUSTOM', 'image' => $image, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $input = app(TelegramTemplateQuery::class)->testForm($u, $module, $code) + ['destination_id' => $d->id];
    $input['confirmed'] = true;

    return [$u, $b, $d, $template, $input];
}
it('C349 sends one fake event test with its saved photo while keeping automatic notices paused', function ($module, $code) {
    [$u,$b,$d,$t,$input] = templateSendFixture($module, $code);
    Http::fake(['https://api.telegram.org/bot'.$b->token.'/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 7, 'chat' => ['id' => -100123]]])]);
    $a = app(SendTelegramTestMessageAction::class);
    $result = $a->execute($u, $input);
    expect($result['status'])->toBe('SENT');
    $a->execute($u, $input);
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_contains(collect($r->data())->firstWhere('name', 'caption')['contents'], 'PRUEBA DE NOTIFICACIÓN') && $r->hasFile('photo', base64_decode($t->image['content'])));
    expect($b->fresh()->is_enabled)->toBeFalse()->and($d->fresh()->is_enabled)->toBeFalse()->and(TelegramDispatch::count())->toBe(0);
    $request = TelegramConfigurationChange::where('kind', 'BOT_TEST_REQUEST')->sole();
    $detail = app(TelegramDeliveryQuery::class)->testDetail($u, $request->id);
    expect($detail['media']['sha256'])->toBe($t->image['sha256'])->and($detail['message'])->toContain('Mensaje de', 'DATOS FICTICIOS')->not->toContain('No se envía')->and($detail['event_code'])->toBe($code)->and($detail['presentation_revision'])->toBe(1);
    expect(json_encode($request->snapshot))->not->toContain($t->image['content']);
    Queue::assertNothingPushed();
})->with(function () {
    $catalog = new TelegramEventCatalog;
    foreach (['CAJAS', 'PRESTAMOS', 'TARJETAS', 'ACCESORIOS'] as $module) {
        foreach (array_keys($catalog->events($module)) as $code) {
            yield $code => [$module, $code];
        }
    }
});
it('C349 consumes the request before HTTP and prevents reentry from sending the event again', function () {
    [$u,$b,$d,$t,$input] = templateSendFixture();
    $a = app(SendTelegramTestMessageAction::class);
    Http::fake(['https://api.telegram.org/bot'.$b->token.'/sendPhoto' => function () use ($a, $u, $input) {
        expect(DB::transactionLevel())->toBe(0)->and($a->execute($u, $input)['status'])->toBe('PENDING');

        return Http::response(['ok' => true, 'result' => ['message_id' => 7, 'chat' => ['id' => -100123]]]);
    }]);
    expect($a->execute($u, $input)['status'])->toBe('SENT');
    Http::assertSentCount(1);
});
it('C349 cancels a stale template test before consuming or sending it', function () {
    [$u,$b,$d,$t,$input] = templateSendFixture();
    app(SaveTelegramTemplateAction::class)->execute($u, ['module' => 'CAJAS', 'event_code' => 'CJ_APERTURA', 'body' => "Actualizado\n{{detalle}}", 'image_mode' => 'NONE', 'expected_revision' => 1, 'request_key' => (string) Str::uuid()]);
    expect(fn () => app(SendTelegramTestMessageAction::class)->execute($u, $input))->toThrow(ValidationException::class);
    expect(TelegramConfigurationChange::where('kind', 'BOT_TEST_REQUEST')->count())->toBe(0);
    Http::assertNothingSent();
});
it('C349 never retries an uncertain template photo and retains its historical image after editing', function () {
    [$u,$b,$d,$t,$input] = templateSendFixture();
    $a = app(SendTelegramTestMessageAction::class);
    Http::fake(['https://api.telegram.org/bot'.$b->token.'/sendPhoto' => Http::failedConnection()]);
    expect($a->execute($u, $input)['status'])->toBe('UNCERTAIN');
    app(SaveTelegramTemplateAction::class)->execute($u, ['module' => 'CAJAS', 'event_code' => 'CJ_APERTURA', 'body' => "Nuevo formato\n{{detalle}}", 'image_mode' => 'NONE', 'expected_revision' => 1, 'request_key' => (string) Str::uuid()]);
    expect($a->execute($u, $input)['status'])->toBe('UNCERTAIN');
    Http::assertSentCount(1);
    $request = TelegramConfigurationChange::where('kind', 'BOT_TEST_REQUEST')->sole();
    $detail = app(TelegramDeliveryQuery::class)->testDetail($u, $request->id);
    expect($detail['media']['sha256'])->toBe($t->image['sha256'])->and($detail['message'])->not->toContain('Nuevo formato');
});
it('C349 sends an event test through its row action and keeps the result in the normal test history', function () {
    [$u,$b,$d] = templateSendFixture();
    Http::fake(['https://api.telegram.org/bot'.$b->token.'/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 7, 'chat' => ['id' => -100123]]])]);
    Livewire::test(TelegramEvents::class)->mountAction('templateTest', ['module' => 'CAJAS', 'event_code' => 'CJ_APERTURA'])->setActionData(['destination_id' => $d->id, 'confirmed' => true])->callMountedAction()->assertHasNoActionErrors();
    expect(TelegramConfigurationChange::where('kind', 'BOT_TEST_RESULT')->sole()->snapshot['status'])->toBe('SENT');
    Http::assertSentCount(1);
});
