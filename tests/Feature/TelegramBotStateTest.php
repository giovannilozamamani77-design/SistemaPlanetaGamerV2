<?php

use App\Domain\Notificaciones\Actions\ProcessTelegramDeliveryAction;
use App\Domain\Notificaciones\Actions\QueueTelegramNoticeAction;
use App\Domain\Notificaciones\Actions\SetTelegramBotStateAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Filament\Pages\Notificaciones\TelegramSettings;
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
function stateFixture(string $module = 'CAJAS', string $code = 'CJ_CIERRE'): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    Http::preventStrayRequests();
    Queue::fake();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);
    $b = TelegramBotSetting::create(['module' => $module, 'name' => 'Bot simulado', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', $module), 'revision' => 1, 'updated_by' => $u->id]);
    $d = TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $u->id, 'name' => 'Destino simulado', 'chat_id' => '-100123', 'kind' => 'GROUP', 'revision' => 1, 'updated_by' => $u->id]);
    $e = TelegramEventSetting::create(['bot_id' => $b->id, 'event_code' => $code, 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $u->id]);
    $e->destinations()->attach($d->id, ['destination_revision' => 1]);
    foreach (['BOT_CHECK' => $b->id, 'DESTINATION_CHECK' => $d->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['status' => 'CONNECTED', 'after' => ['revision' => 1], 'bot_revision' => 1], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }

    return [$u, $b, $d, $e];
}
function stateInput($b, $d, bool $enabled = true): array
{
    return ['bot_id' => $b->id, 'enabled' => $enabled, 'expected_revision' => $b->fresh()->revision, 'expected_state_version' => (int) TelegramConfigurationChange::where('kind', 'BOT_STATE')->where('source_id', $b->id)->max('id'), 'destination_ids' => $enabled ? [$d->id] : [], 'destination_revisions' => [$d->id => $d->fresh()->revision], 'request_key' => (string) Str::uuid()];
}
it('keeps defaults paused and activates only the selected bot and destinations without HTTP', function ($module, $code) {
    [$u,$b,$d] = stateFixture($module, $code);
    expect($b->fresh()->is_enabled)->toBeFalse()->and($d->fresh()->is_enabled)->toBeFalse();
    $extra = $d->replicate();
    $extra->chat_id = '-100456';
    $extra->save();
    $input = stateInput($b, $d);
    $a = app(SetTelegramBotStateAction::class);
    $a->execute($u, $input);
    $a->execute($u, $input);
    expect($b->fresh()->is_enabled)->toBeTrue()->and($b->fresh()->revision)->toBe(1)->and($d->fresh()->is_enabled)->toBeTrue()->and($extra->fresh()->is_enabled)->toBeFalse();
    expect(TelegramConfigurationChange::where('kind', 'BOT_STATE')->count())->toBe(1);
    expect(json_encode(TelegramConfigurationChange::where('kind', 'BOT_STATE')->sole()->snapshot))->not->toContain($b->token)->not->toContain($d->chat_id);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([['CAJAS', 'CJ_CIERRE'], ['PRESTAMOS', 'PR_ALTA'], ['TARJETAS', 'TJ_APERTURA'], ['ACCESORIOS', 'AC_VENTA_TELEFONO']]);
it('sends new notices with simulated Telegram only and pause never revives pending or omitted notices', function () {
    [$u,$b,$d] = stateFixture();
    $a = app(SetTelegramBotStateAction::class);
    $q = app(QueueTelegramNoticeAction::class);
    $old = $q->execute('CAJAS', 'CJ_CIERRE', 'close:1', 'Simulado 1');
    $a->execute($u, stateInput($b, $d));
    $q->execute('CAJAS', 'CJ_CIERRE', 'close:2', 'Simulado 2');
    Http::fake(['https://api.telegram.org/bot'.$b->token.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => -100123]]])]);
    $processor = app(ProcessTelegramDeliveryAction::class);
    $processor->execute(TelegramDelivery::sole()->id);
    expect(TelegramDelivery::sole()->status)->toBe('ENVIADA');
    Http::assertSentCount(1);
    $q->execute('CAJAS', 'CJ_CIERRE', 'close:3', 'Simulado 3');
    $pending = TelegramDelivery::latest('id')->first();
    $a->execute($u, stateInput($b, $d, false));
    expect($pending->fresh()->status)->toBe('OMITIDA')->and($pending->fresh()->last_code)->toBe('PAUSED');
    $a->execute($u, stateInput($b, $d));
    $processor->execute($pending->id);
    $q->execute('CAJAS', 'CJ_CIERRE', 'close:1', 'Simulado 1');
    expect($old->fresh()->message)->toBeNull()->and(TelegramDispatch::count())->toBe(3)->and(TelegramDelivery::count())->toBe(2);
    Http::assertSentCount(1);
});
it('rejects unverified stale unassigned foreign inactive recipients and emergency pause', function ($case) {
    [$u,$b,$d,$e] = stateFixture();
    $input = stateInput($b, $d);
    match ($case) {
        'check' => TelegramConfigurationChange::where('kind', 'DESTINATION_CHECK')->delete(),
        'bot_revision' => $b->increment('revision'),
        'destination_revision' => $d->increment('revision'),
        'assignment' => $e->destinations()->detach(),
        'event' => $e->update(['prepared' => false]),
        'recipient' => $u->forceFill(['is_active' => false])->save(),
        'foreign' => $d->update(['bot_id' => TelegramBotSetting::create(['module' => 'PRESTAMOS', 'name' => 'Otro', 'token' => '654321:'.str_repeat('B', 35), 'token_fingerprint' => hash('sha256', 'other'), 'revision' => 1, 'updated_by' => $u->id])->id]),
        'global' => config(['telegram.sending_enabled' => false]),
    };
    expect(fn () => app(SetTelegramBotStateAction::class)->execute($u, $input))->toThrow($case === 'recipient' ? AuthorizationException::class : ValidationException::class);
    expect($b->fresh()->is_enabled)->toBeFalse()->and(TelegramConfigurationChange::where('kind', 'BOT_STATE')->count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['check', 'bot_revision', 'destination_revision', 'assignment', 'event', 'recipient', 'foreign', 'global']);
it('rejects stale state including ABA and never reapplies an old activation UUID', function () {
    [$u,$b,$d] = stateFixture();
    $a = app(SetTelegramBotStateAction::class);
    $first = stateInput($b, $d);
    $stale = stateInput($b, $d);
    $a->execute($u, $first);
    $a->execute($u, stateInput($b, $d, false));
    $a->execute($u, $first);
    expect($b->fresh()->is_enabled)->toBeFalse();
    expect(fn () => $a->execute($u, $stale))->toThrow(ValidationException::class);
    $a->execute($u, stateInput($b, $d));
    expect($b->fresh()->is_enabled)->toBeTrue();
    Http::assertNothingSent();
});
it('forbids cashier and manager even with the permission', function ($role) {
    [$u,$b,$d] = stateFixture();
    $u->syncRoles([$role]);
    $u->givePermissionTo('notificaciones.telegram.configurar');
    expect(fn () => app(SetTelegramBotStateAction::class)->execute($u, stateInput($b, $d)))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
})->with(['cajera', 'encargado']);
it('rolls back state and audit together', function () {
    [$u,$b,$d] = stateFixture();
    try {
        DB::transaction(function () use ($u, $b, $d) {
            app(SetTelegramBotStateAction::class)->execute($u, stateInput($b, $d));
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect($b->fresh()->is_enabled)->toBeFalse()->and($d->fresh()->is_enabled)->toBeFalse()->and(TelegramConfigurationChange::where('kind', 'BOT_STATE')->count())->toBe(0);
    Http::assertNothingSent();
});
it('mounts and saves the bot control through Livewire without browser or messages', function () {
    [$u,$b,$d] = stateFixture();
    $page = Livewire::test(TelegramSettings::class)->assertSee('Activar')->mountAction('state', ['id' => $b->id]);
    $page->setActionData(['enabled' => true, 'destination_ids' => [$d->id]])->callMountedAction()->assertHasNoActionErrors()->assertSee('Activo');
    expect($b->fresh()->is_enabled)->toBeTrue();
    $page->mountAction('state', ['id' => $b->id])->setActionData(['enabled' => false])->callMountedAction()->assertHasNoActionErrors()->assertSee('Pausado');
    expect($b->fresh()->is_enabled)->toBeFalse();
    Http::assertNothingSent();
});

it('rejects invalid destination selection and a revoked destination owner', function ($case) {
    [$u,$b,$d] = stateFixture();
    $input = stateInput($b, $d);
    if ($case === 'empty') {
        $input['destination_ids'] = [];
    }
    if ($case === 'duplicate') {
        $input['destination_ids'] = [$d->id, $d->id];
    }
    if ($case === 'unknown') {
        $input['destination_ids'] = [99999];
    }
    if ($case === 'owner_inactive' || $case === 'owner_cashier') {
        $owner = User::factory()->create();
        $owner->assignRole($case === 'owner_cashier' ? 'cajera' : 'encargado');
        if ($case === 'owner_inactive') {
            $owner->forceFill(['is_active' => false])->save();
        }
        $d->update(['responsible_id' => $owner->id]);
    }
    expect(fn () => app(SetTelegramBotStateAction::class)->execute($u, $input))->toThrow(ValidationException::class);
    expect($b->fresh()->is_enabled)->toBeFalse();
    Http::assertNothingSent();
})->with(['empty', 'duplicate', 'unknown', 'owner_inactive', 'owner_cashier']);
it('keeps another active bot and its pending notice intact when pausing one bot', function () {
    [$u,$b,$d] = stateFixture();
    $other = $b->replicate();
    $other->module = 'PRESTAMOS';
    $other->token_fingerprint = hash('sha256', 'loans');
    $other->is_enabled = true;
    $other->save();
    $dest = $d->replicate();
    $dest->bot_id = $other->id;
    $dest->is_enabled = true;
    $dest->save();
    $e = TelegramEventSetting::create(['bot_id' => $other->id, 'event_code' => 'PR_ALTA', 'prepared' => true, 'revision' => 1, 'bot_revision' => 1, 'template_version' => 1, 'updated_by' => $u->id]);
    $e->destinations()->attach($dest->id, ['destination_revision' => 1]);
    foreach (['BOT_CHECK' => $other->id, 'DESTINATION_CHECK' => $dest->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['status' => 'CONNECTED', 'after' => ['revision' => 1], 'bot_revision' => 1], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }
    $a = app(SetTelegramBotStateAction::class);
    $a->execute($u, stateInput($b, $d));
    app(QueueTelegramNoticeAction::class)->execute('PRESTAMOS', 'PR_ALTA', 'loan:1', 'Prueba');
    $a->execute($u, stateInput($b, $d, false));
    expect($other->fresh()->is_enabled)->toBeTrue()->and($dest->fresh()->is_enabled)->toBeTrue()->and(TelegramDelivery::sole()->status)->toBe('PENDIENTE');
    Http::assertNothingSent();
});
it('does not revive a retry whose HTTP response arrives across a pause and resume', function () {
    [$u,$b,$d] = stateFixture();
    test()->freezeTime();
    $a = app(SetTelegramBotStateAction::class);
    $a->execute($u, stateInput($b, $d));
    app(QueueTelegramNoticeAction::class)->execute('CAJAS', 'CJ_CIERRE', 'close:race', 'Prueba');
    Http::fake(['https://api.telegram.org/bot'.$b->token.'/sendMessage' => function () use ($a, $u, $b, $d) {
        $a->execute($u, stateInput($b, $d, false));
        $a->execute($u, stateInput($b, $d));

        return Http::response(['ok' => false, 'error_code' => 500], 500);
    }]);
    $process = app(ProcessTelegramDeliveryAction::class);
    $process->execute(TelegramDelivery::sole()->id);
    test()->travel(60)->seconds();
    $process->execute(TelegramDelivery::sole()->id);
    expect(TelegramDelivery::sole()->status)->toBe('CANCELADA')->and(TelegramDelivery::sole()->last_code)->toBe('CONFIG_CHANGED');
    Http::assertSentCount(1);
});
it('allows emergency pausing and rejects UUID reuse with changed payload or actor', function () {
    [$u,$b,$d] = stateFixture();
    $a = app(SetTelegramBotStateAction::class);
    $input = stateInput($b, $d);
    $a->execute($u, $input);
    expect(fn () => $a->execute($u, array_replace($input, ['enabled' => false])))->toThrow(ValidationException::class);
    $other = User::factory()->create();
    $other->assignRole('administrador');
    expect(fn () => $a->execute($other, $input))->toThrow(AuthorizationException::class);
    config(['telegram.sending_enabled' => false]);
    $a->execute($u, stateInput($b, $d, false));
    expect($b->fresh()->is_enabled)->toBeFalse();
    Http::assertNothingSent();
});
it('shows actionable validation errors inside the modal without activating', function () {
    [$u,$b,$d] = stateFixture();
    Livewire::test(TelegramSettings::class)->mountAction('state', ['id' => $b->id])->setActionData(['enabled' => true, 'destination_ids' => []])->callMountedAction()->assertHasActionErrors(['destination_ids']);
    expect($b->fresh()->is_enabled)->toBeFalse();
    Http::assertNothingSent();
});
