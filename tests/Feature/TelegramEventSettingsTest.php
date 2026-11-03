<?php

use App\Domain\Notificaciones\Actions\SaveTelegramBotAction;
use App\Domain\Notificaciones\Actions\SaveTelegramDestinationAction;
use App\Domain\Notificaciones\Actions\SaveTelegramEventAction;
use App\Domain\Notificaciones\Data\TelegramEventCatalog;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Queries\TelegramEventsQuery;
use App\Filament\Pages\Notificaciones\TelegramEvents;
use App\Filament\Pages\Notificaciones\TelegramSettings;
use App\Models\User;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
function eventFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    Http::preventStrayRequests();
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);
    $b = app(SaveTelegramBotAction::class)->execute($u, ['module' => 'CAJAS', 'name' => 'Test bot', 'token' => '123456:'.str_repeat('A', 35), 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $d = app(SaveTelegramDestinationAction::class)->execute($u, ['bot_id' => $b->id, 'responsible_id' => $u->id, 'name' => 'Test destination', 'kind' => 'GROUP', 'chat_id' => '-100123', 'private_confirmed' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);

    return [$u, $b, $d];
}
function eventInput($bot, $dest, array $changes = []): array
{
    return array_replace(['module' => 'CAJAS', 'event_code' => 'CJ_CIERRE', 'prepared' => true, 'destination_ids' => [$dest->id], 'destination_revisions' => [$dest->id => 1], 'expected_revision' => 0, 'bot_revision' => 1, 'request_key' => (string) Str::uuid()], $changes);
}
it('prepares an event without enabling bots destinations or messages and replays once', function () {
    [$u,$b,$d] = eventFixture();
    $v = eventInput($b, $d);
    $a = app(SaveTelegramEventAction::class);
    $e = $a->execute($u, $v);
    $a->execute($u, $v);
    expect($e->prepared)->toBeTrue()->and($e->revision)->toBe(1)->and($e->destinations->pluck('id')->all())->toBe([$d->id]);
    expect(TelegramEventSetting::count())->toBe(1)->and(TelegramConfigurationChange::where('kind', 'EVENT_SETTING')->count())->toBe(1);
    expect($b->fresh()->is_enabled)->toBeFalse()->and($d->fresh()->is_enabled)->toBeFalse();
    Http::assertNothingSent();
    expect(json_encode(TelegramConfigurationChange::where('kind', 'EVENT_SETTING')->sole()->toArray()))->not->toContain($b->token)->not->toContain($d->chat_id);
});
it('rejects unknown cross-module and deferred events', function ($code) {
    [$u,$b,$d] = eventFixture();
    expect(fn () => app(SaveTelegramEventAction::class)->execute($u, eventInput($b, $d, ['event_code' => $code])))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with(['PR_ALTA', 'TIME_LATE', 'TJ_STOCK_MINIMO', '<script>']);
it('rejects activation and duplicate destinations', function ($changes) {
    [$u,$b,$d] = eventFixture();
    $changes = $changes === 'duplicate' ? ['destination_ids' => [$d->id, $d->id]] : $changes;
    expect(fn () => app(SaveTelegramEventAction::class)->execute($u, eventInput($b, $d, $changes)))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([[['is_enabled' => true]], [['enabled' => true]], ['duplicate'], [['prepared' => true, 'destination_ids' => []]]]);
it('can remove all assignments while leaving an event unprepared', function () {
    [$u,$b,$d] = eventFixture();
    $a = app(SaveTelegramEventAction::class);
    $e = $a->execute($u, eventInput($b, $d));
    $e = $a->execute($u, eventInput($b, $d, ['prepared' => false, 'destination_ids' => [], 'expected_revision' => 1]));
    expect($e->prepared)->toBeFalse()->and($e->destinations->count())->toBe(0)->and($e->revision)->toBe(2);
});
it('rejects stale bot destination and event revisions', function ($which) {
    [$u,$b,$d] = eventFixture();
    $v = eventInput($b, $d);
    $a = app(SaveTelegramEventAction::class);
    if ($which === 'bot') {
        $b->increment('revision');
    } elseif ($which === 'destination') {
        $d->increment('revision');
    } else {
        $a->execute($u, $v);
    }
    $v['request_key'] = (string) Str::uuid();
    expect(fn () => $a->execute($u, $v))->toThrow(ValidationException::class);
})->with(['bot', 'destination', 'event']);
it('rejects cross-bot assignments and revoked responsible users', function ($which) {
    [$u,$b,$d] = eventFixture();
    if ($which === 'owner') {
        $u->forceFill(['is_active' => false])->save();
        $other = User::factory()->create();
        $other->assignRole('administrador');
        $u = $other;
    } else {
        $other = app(SaveTelegramBotAction::class)->execute($u, ['module' => 'TARJETAS', 'name' => 'Other', 'token' => '654321:'.str_repeat('B', 35), 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
        $d->update(['bot_id' => $other->id]);
    }
    expect(fn () => app(SaveTelegramEventAction::class)->execute($u, eventInput($b, $d)))->toThrow(ValidationException::class);
})->with(['owner', 'bot']);
it('denies cashier and encargado even with direct configuration permission', function ($role) {
    [$u,$b,$d] = eventFixture();
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $actor->givePermissionTo('panel.acceder', 'notificaciones.telegram.configurar');
    expect(fn () => app(SaveTelegramEventAction::class)->execute($actor, eventInput($b, $d)))->toThrow(AuthorizationException::class);
    expect(fn () => app(TelegramEventsQuery::class)->forModule($actor, 'CAJAS'))->toThrow(AuthorizationException::class);
})->with(['cajera', 'encargado']);
it('rejects request UUID reused by another actor or with changed data', function () {
    [$u,$b,$d] = eventFixture();
    $v = eventInput($b, $d);
    $a = app(SaveTelegramEventAction::class);
    $a->execute($u, $v);
    expect(fn () => $a->execute($u, array_replace($v, ['prepared' => false])))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('administrador');
    expect(fn () => $a->execute($other, $v))->toThrow(AuthorizationException::class);
});
it('rolls back event and assignments when audit persistence fails', function () {
    [$u,$b,$d] = eventFixture();
    DB::statement("CREATE TRIGGER fail_event_audit BEFORE INSERT ON telegram_configuration_changes WHEN NEW.kind = 'EVENT_SETTING' BEGIN SELECT RAISE(ABORT, 'test'); END");
    expect(fn () => app(SaveTelegramEventAction::class)->execute($u, eventInput($b, $d)))->toThrow(QueryException::class);
    expect(TelegramEventSetting::count())->toBe(0)->and(DB::table('telegram_event_destinations')->count())->toBe(0);
});
it('previews examples for every supported event without business data or HTTP', function () {
    [$u,$b,$d] = eventFixture();
    $catalog = app(TelegramEventCatalog::class);
    foreach (['CAJAS', 'PRESTAMOS', 'TARJETAS', 'ACCESORIOS'] as $module) {
        foreach ($catalog->events($module) as $code => $entry) {
            $preview = app(TelegramEventsQuery::class)->preview($u, $module, $code);
            expect($preview['message'])->toContain('EJEMPLO')->not->toContain($b->token)->not->toContain($d->chat_id);
        }
    }
    expect($catalog->events('CAJAS'))->toHaveKey('CJ_REPOSICION_GLOBAL');
    Http::assertNothingSent();
});
it('renders module selection preview and configuration through Livewire without HTTP', function () {
    [$u,$b,$d] = eventFixture();
    $page = Livewire::test(TelegramEvents::class)->assertSee('Envíos pausados')->assertSee('Cierre registrado');
    $page->mountAction('preview', ['module' => 'CAJAS', 'event_code' => 'CJ_CIERRE'])->assertActionMounted('preview')->assertMountedActionModalSee('EJEMPLO')->unmountAction();
    $page->mountAction('event', ['module' => 'CAJAS', 'event_code' => 'CJ_CIERRE'])->assertActionDataSet(['prepared' => false, 'expected_revision' => 0]);
    $page->setActionData(['prepared' => true, 'destination_ids' => [$d->id]])->callMountedAction()->assertHasNoActionErrors()->assertSee('Preparado');
    expect(TelegramEventSetting::sole()->prepared)->toBeTrue();
    Livewire::test(TelegramSettings::class)->assertSee('Evento y destinos')->assertSee('CJ_CIERRE');
    Http::assertNothingSent();
});

it('flags changed destination assignments and allows reconfirming their new revision', function () {
    [$u,$b,$d] = eventFixture();
    $a = app(SaveTelegramEventAction::class);
    $a->execute($u, eventInput($b, $d));
    $q = app(TelegramEventsQuery::class);
    expect($q->forModule($u, 'CAJAS')['events']['CJ_CIERRE']['review_required'])->toBeFalse();
    $d->increment('revision');
    expect($q->forModule($u, 'CAJAS')['events']['CJ_CIERRE']['review_required'])->toBeTrue();
    $form = $q->form($u, 'CAJAS', 'CJ_CIERRE');
    $a->execute($u, $form);
    expect($q->forModule($u, 'CAJAS')['events']['CJ_CIERRE']['review_required'])->toBeFalse();
    Http::assertNothingSent();
});
it('does not overwrite the event or audit when the destination changes while its modal is open', function () {
    [$u,$b,$d] = eventFixture();
    $page = Livewire::test(TelegramEvents::class)->mountAction('event', ['module' => 'CAJAS', 'event_code' => 'CJ_CIERRE']);
    $d->increment('revision');
    $page->setActionData(['prepared' => true, 'destination_ids' => [$d->id]])->callMountedAction()->assertHasActionErrors(['destination_ids']);
    expect(TelegramEventSetting::count())->toBe(0)->and(TelegramConfigurationChange::where('kind', 'EVENT_SETTING')->count())->toBe(0);
});
it('denies an inactive or individually restricted administrator', function ($case) {
    [$u,$b,$d] = eventFixture();
    if ($case === 'inactive') {
        $u->forceFill(['is_active' => false])->save();
    } else {
        $u->restrictedPermissions()->attach(Permission::findByName('notificaciones.telegram.configurar')->id);
    }
    expect(fn () => app(SaveTelegramEventAction::class)->execute($u, eventInput($b, $d)))->toThrow(AuthorizationException::class);
    expect(fn () => app(TelegramEventsQuery::class)->preview($u, 'CAJAS', 'CJ_CIERRE'))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
})->with(['inactive', 'restricted']);
it('keeps examples available without configuring a bot but cannot save assignments', function () {
    [$u,$b,$d] = eventFixture();
    $q = app(TelegramEventsQuery::class);
    expect($q->forModule($u, 'PRESTAMOS')['bot'])->toBeNull();
    expect($q->preview($u, 'PRESTAMOS', 'PR_ALTA')['message'])->toContain('Capital: Bs 200')->toContain('Interés: Bs 30');
    expect(fn () => $q->form($u, 'PRESTAMOS', 'PR_ALTA'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});
it('opens the module requested from its bot card and switches modules without writes', function () {
    [$u,$b,$d] = eventFixture();
    $this->get(TelegramEvents::getUrl(['module' => 'TARJETAS']))->assertOk()->assertSee('Compra de tarjetas')->assertDontSee('Cierre registrado');
    Livewire::test(TelegramEvents::class)->call('selectModule', 'ACCESORIOS')->assertSee('Pago de comisiones registrado')->assertDontSee('Cierre registrado');
    expect(TelegramEventSetting::count())->toBe(0);
    Http::assertNothingSent();
});
it('escapes destination names in the event screen and modal', function () {
    [$u,$b,$d] = eventFixture();
    $d->update(['name' => '<script>alert(1)</script>']);
    app(SaveTelegramEventAction::class)->execute($u, eventInput($b, $d));
    Livewire::test(TelegramEvents::class)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
});
it('keeps Entel credit example aligned with the recorded bonus and cash rules', function () {
    [$u] = eventFixture();
    $message = app(TelegramEventsQuery::class)->preview($u, 'TARJETAS', 'TJ_RECARGA')['message'];
    expect($message)->toContain('Monto cobrado: Bs 100')->toContain('Bonificación al cliente: Bs 8')->toContain('Total para el cliente: Bs 108')->not->toContain('Cobrado: Bs 92');
});
it('stores separate choices for a second bot without exposing first bot destinations', function () {
    [$u,$b,$d] = eventFixture();
    $other = app(SaveTelegramBotAction::class)->execute($u, ['module' => 'ACCESORIOS', 'name' => 'Second', 'token' => '654321:'.str_repeat('B', 35), 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    $otherDest = app(SaveTelegramDestinationAction::class)->execute($u, ['bot_id' => $other->id, 'responsible_id' => $u->id, 'name' => 'Accessories only', 'kind' => 'PRIVATE', 'chat_id' => '555555', 'private_confirmed' => true, 'expected_revision' => 0, 'request_key' => (string) Str::uuid()]);
    app(SaveTelegramEventAction::class)->execute($u, eventInput($b, $d));
    app(SaveTelegramEventAction::class)->execute($u, eventInput($other, $otherDest, ['module' => 'ACCESORIOS', 'event_code' => 'AC_VENTA_TELEFONO']));
    $q = app(TelegramEventsQuery::class);
    expect($q->destinationOptions($u, 'CAJAS'))->toHaveKey($d->id)->not->toHaveKey($otherDest->id);
    expect($q->forModule($u, 'ACCESORIOS')['events']['AC_VENTA_TELEFONO']['destinations'])->toBe(['Accessories only']);
    Http::assertNothingSent();
});
it('keeps the event unique and assignment unique even when another insertion is attempted', function () {
    [$u,$b,$d] = eventFixture();
    $e = app(SaveTelegramEventAction::class)->execute($u, eventInput($b, $d));
    expect(fn () => DB::table('telegram_event_settings')->insert(['bot_id' => $b->id, 'event_code' => 'CJ_CIERRE', 'prepared' => false, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $u->id]))->toThrow(QueryException::class);
    expect(fn () => DB::table('telegram_event_destinations')->insert(['event_setting_id' => $e->id, 'destination_id' => $d->id, 'destination_revision' => 1]))->toThrow(QueryException::class);
});
