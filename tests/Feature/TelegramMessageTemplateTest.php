<?php

use App\Domain\Notificaciones\Actions\QueueTelegramNoticeAction;
use App\Domain\Notificaciones\Actions\SaveTelegramTemplateAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Models\TelegramMessageTemplate;
use App\Domain\Notificaciones\Services\TelegramPhotoSnapshot;
use App\Domain\Notificaciones\Services\TelegramTemplateRenderer;
use App\Filament\Pages\Notificaciones\TelegramEvents;
use App\Models\User;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
function templateFixture(): User
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    Http::preventStrayRequests();
    Queue::fake();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);

    return $u;
}
function templateData(array $changes = []): array
{
    return array_replace(['module' => 'CAJAS', 'event_code' => 'CJ_APERTURA', 'body' => "Mi encabezado\n{{titulo}}\n{{detalle}}\nGracias · {{modulo}}", 'image_mode' => 'ORIGINAL', 'expected_revision' => 0, 'request_key' => (string) Str::uuid()], $changes);
}
function templateImage(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('imagen.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII='));
}
it('C349 saves an encrypted template with audit revision and idempotence without configuring or sending a bot', function () {
    $u = templateFixture();
    $data = templateData();
    $a = app(SaveTelegramTemplateAction::class);
    $t = $a->execute($u, $data);
    expect($t->revision)->toBe(1)->and($t->body)->toBe($data['body'])->and($t->getRawOriginal('body'))->not->toContain('Mi encabezado');
    expect($a->execute($u, $data)->id)->toBe($t->id)->and(TelegramMessageTemplate::count())->toBe(1)->and(TelegramConfigurationChange::where('kind', 'MESSAGE_TEMPLATE')->count())->toBe(1)->and(TelegramBotSetting::count())->toBe(0);
    expect(fn () => $a->execute($u, templateData()))->toThrow(ValidationException::class);
    $next = $a->execute($u, templateData(['expected_revision' => 1, 'body' => "Nuevo\n{{detalle}}"]));
    expect($next->revision)->toBe(2);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
it('C349 rejects missing repeated or unknown automatic markers', function ($body) {
    $u = templateFixture();
    expect(fn () => app(SaveTelegramTemplateAction::class)->execute($u, templateData(['body' => $body])))->toThrow(ValidationException::class);
    expect(TelegramMessageTemplate::count())->toBe(0);
})->with(['Sin detalle', '{{detalle}} {{detalle}}', '{{detalle}} {{token}}', str_repeat('X', 1201).'{{detalle}}']);
it('C349 accepts a custom image and can restore the original or remove it', function () {
    $u = templateFixture();
    $a = app(SaveTelegramTemplateAction::class);
    $t = $a->execute($u, templateData(['image_mode' => 'CUSTOM', 'image' => templateImage()]));
    expect($t->image['mime'])->toBe('image/png')->and($t->getRawOriginal('image'))->not->toContain('imagen.png');
    $r = app(TelegramTemplateRenderer::class)->apply('CAJAS', 'CJ_APERTURA', "CAJA ABIERTA\nTotal: Bs 100", null);
    expect($r['message'])->toContain('Mi encabezado', 'Total: Bs 100')->and($r['media']['sha256'])->toBe($t->image['sha256']);
    $a->execute($u, templateData(['image_mode' => 'NONE', 'expected_revision' => 1]));
    expect(app(TelegramTemplateRenderer::class)->apply('CAJAS', 'CJ_APERTURA', "Título\nDatos", $r['media'])['media'])->toBeNull();
    $a->execute($u, templateData(['body' => "{{titulo}}\n{{detalle}}", 'expected_revision' => 2]));
    expect(app(TelegramTemplateRenderer::class)->apply('CAJAS', 'CJ_APERTURA', "Título\nDatos", null)['message'])->toBe("Título\nDatos");
});
it('C349 rejects an invalid image or a missing custom image', function ($missing) {
    $u = templateFixture();
    $data = templateData(['image_mode' => 'CUSTOM']);
    if (! $missing) {
        $data['image'] = UploadedFile::fake()->createWithContent('bad.png', 'not an image');
    }
    expect(fn () => app(SaveTelegramTemplateAction::class)->execute($u, $data))->toThrow(ValidationException::class);
})->with([true, false]);
it('C349 keeps data as literal text and never evaluates embedded code', function () {
    $u = templateFixture();
    app(SaveTelegramTemplateAction::class)->execute($u, templateData(['body' => "<?php test(); ?>\n{{detalle}}"]));
    $r = app(TelegramTemplateRenderer::class)->apply('CAJAS', 'CJ_APERTURA', "Título\n{{token}} · Bs 100", null);
    expect($r['message'])->toContain('<?php test(); ?>', '{{token}} · Bs 100');
});
it('C349 does not permit cashier or inactive administrator template changes', function () {
    $u = templateFixture();
    $cashier = User::factory()->create();
    $cashier->assignRole('cajera');
    expect(fn () => app(SaveTelegramTemplateAction::class)->execute($cashier, templateData()))->toThrow(AuthorizationException::class);
    $u->forceFill(['is_active' => false])->save();
    expect(fn () => app(SaveTelegramTemplateAction::class)->execute($u, templateData()))->toThrow(AuthorizationException::class);
});
it('C349 applies templates only to new facts while preserving queued snapshots and the source hash', function () {
    $u = templateFixture();
    config(['telegram.sending_enabled' => true]);
    $bot = TelegramBotSetting::create(['module' => 'CAJAS', 'name' => 'Prueba', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', 'fake'), 'is_enabled' => true, 'revision' => 1, 'updated_by' => $u->id]);
    $dest = TelegramDestination::create(['bot_id' => $bot->id, 'responsible_id' => $u->id, 'name' => 'Destino', 'chat_id' => '-100123', 'kind' => 'GROUP', 'is_enabled' => true, 'revision' => 1, 'updated_by' => $u->id]);
    $event = TelegramEventSetting::create(['bot_id' => $bot->id, 'event_code' => 'CJ_APERTURA', 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $u->id]);
    $event->destinations()->attach($dest->id, ['destination_revision' => 1]);
    foreach (['BOT_CHECK' => $bot->id, 'DESTINATION_CHECK' => $dest->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['status' => 'CONNECTED', 'after' => ['revision' => 1], 'bot_revision' => 1], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }
    $queue = app(QueueTelegramNoticeAction::class);
    $text = "CAJA ABIERTA\nTotal: Bs 100";
    $photo = app(TelegramPhotoSnapshot::class)->fromLogo('logoReciboPrestamo.png', $text);
    $old = $queue->execute('CAJAS', 'CJ_APERTURA', 'template-old:1', $text, $photo);
    app(SaveTelegramTemplateAction::class)->execute($u, templateData(['image_mode' => 'CUSTOM', 'image' => templateImage()]));
    expect($queue->execute('CAJAS', 'CJ_APERTURA', 'template-old:1', $text, $photo)->id)->toBe($old->id)->and($old->fresh()->message)->toBe($text);
    $new = $queue->execute('CAJAS', 'CJ_APERTURA', 'template-new:2', $text, $photo);
    expect($new->message)->toContain('Mi encabezado', 'Total: Bs 100')->and($new->deliveries()->sole()->snapshot['presentation_revision'])->toBe(1);
    expect($old->fresh()->media['sha256'])->toBe($photo['sha256']);
    Http::assertNothingSent();
});
it('C349 falls back to a full text notice when the custom caption is too long without truncating data', function () {
    $u = templateFixture();
    app(SaveTelegramTemplateAction::class)->execute($u, templateData(['image_mode' => 'CUSTOM', 'image' => templateImage()]));
    $text = "Título\n".str_repeat('Datos ', 200);
    $r = app(TelegramTemplateRenderer::class)->apply('CAJAS', 'CJ_APERTURA', $text, null);
    expect($r['media'])->toBeNull()->and($r['message'])->toContain(str_repeat('Datos ', 200));
    $original = "Título\n".str_repeat('x', 4080);
    $r = app(TelegramTemplateRenderer::class)->apply('CAJAS', 'CJ_APERTURA', $original, null);
    expect($r['message'])->toBe($original);
});
it('C349 offers editor image selection and a separate test action for every catalogue event', function () {
    templateFixture();
    Livewire::test(TelegramEvents::class)->mountAction('template', ['module' => 'CAJAS', 'event_code' => 'CJ_APERTURA'])->assertActionDataSet(['body' => "{{titulo}}\n{{detalle}}", 'image_mode' => 'ORIGINAL'])->setActionData(['body' => "Aviso de prueba\n{{detalle}}"])->callMountedAction()->assertHasNoActionErrors();
    expect(TelegramMessageTemplate::sole()->body)->toBe("Aviso de prueba\n{{detalle}}");
    Livewire::test(TelegramEvents::class)->assertSee('Plantilla e imagen')->assertSee('Conectado')->assertSee('Pendiente de integrar');
});

it('C349 accepts an image uploaded through the event editor', function () {
    templateFixture();
    Storage::fake('local');
    Livewire::test(TelegramEvents::class)->mountAction('template', ['module' => 'CAJAS', 'event_code' => 'CJ_APERTURA'])->setActionData(['image_mode' => 'CUSTOM', 'image' => [templateImage()]])->callMountedAction()->assertHasNoActionErrors();
    expect(TelegramMessageTemplate::sole()->image_mode)->toBe('CUSTOM')->and(TelegramMessageTemplate::sole()->image['mime'])->toBe('image/png');
});
it('C349 rolls back a template update if its audit cannot be saved', function () {
    $u = templateFixture();
    DB::statement("CREATE TRIGGER fail_template_audit BEFORE INSERT ON telegram_configuration_changes WHEN NEW.kind='MESSAGE_TEMPLATE' BEGIN SELECT RAISE(ABORT,'simulated'); END");
    expect(fn () => app(SaveTelegramTemplateAction::class)->execute($u, templateData()))->toThrow(QueryException::class);
    expect(TelegramMessageTemplate::count())->toBe(0);
});

it('C349 rejects an oversized image before storing any template', function () {
    $u = templateFixture();
    $small = templateImage();
    $bytes = file_get_contents($small->getRealPath()).str_repeat(' ', 262144);
    $file = UploadedFile::fake()->createWithContent('grande.png', $bytes);
    expect(fn () => app(SaveTelegramTemplateAction::class)->execute($u, templateData(['image_mode' => 'CUSTOM', 'image' => $file])))->toThrow(ValidationException::class);
    expect(TelegramMessageTemplate::count())->toBe(0);
});

it('C349 does not restore an unwanted original image when a long custom message falls back', function ($mode) {
    $u = templateFixture();
    $data = templateData(['image_mode' => $mode]);
    if ($mode === 'CUSTOM') {
        $data['image'] = templateImage();
    }
    app(SaveTelegramTemplateAction::class)->execute($u, $data);
    $original = "Título\n".str_repeat('x', 4080);
    $photo = app(TelegramPhotoSnapshot::class)->fromLogo('logoReciboPrestamo.png', "Título\nResumen guardado");
    $result = app(TelegramTemplateRenderer::class)->apply('CAJAS', 'CJ_APERTURA', $original, $photo);
    expect($result['message'])->toBe($original)->and($result['media'] === null)->toBeTrue();
})->with(['NONE', 'CUSTOM']);
