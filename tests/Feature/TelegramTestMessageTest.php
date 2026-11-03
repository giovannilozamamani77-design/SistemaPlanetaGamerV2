<?php

use App\Domain\Notificaciones\Actions\SendTelegramTestMessageAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use App\Filament\Pages\Notificaciones\TelegramSettings;
use App\Models\User;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\ConnectionException;
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
function manualTestFixture(string $module = 'CAJAS'): array
{
    test()->freezeTime();
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    Http::preventStrayRequests();
    Queue::fake();
    Filament::setCurrentPanel(Filament::getPanel('inicio'));
    $u = User::factory()->create();
    $u->assignRole('administrador');
    test()->actingAs($u);
    $b = TelegramBotSetting::create(['module' => $module, 'name' => 'Bot ficticio', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', $module), 'revision' => 1, 'updated_by' => $u->id]);
    $d = TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $u->id, 'name' => 'Destino ficticio', 'chat_id' => '-100123', 'kind' => 'GROUP', 'revision' => 1, 'updated_by' => $u->id]);
    foreach (['BOT_CHECK' => $b->id, 'DESTINATION_CHECK' => $d->id] as $kind => $id) {
        TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => $kind, 'source_id' => $id, 'snapshot' => ['status' => 'CONNECTED', 'after' => ['revision' => 1], 'bot_revision' => 1], 'request_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $kind), 'created_at' => now()]);
    }

    return [$u, $b, $d];
}
function manualTestInput($b, $d): array
{
    return ['bot_id' => $b->id, 'destination_id' => $d->id, 'expected_revision' => $b->revision, 'destination_revisions' => [$d->id => $d->revision], 'confirmed' => true, 'request_key' => (string) Str::uuid()];
}
function manualTestFake($b, $body = null, int $status = 200): void
{
    Http::fake(['https://api.telegram.org/bot'.$b->token.'/sendMessage' => Http::response($body ?? ['ok' => true, 'result' => ['message_id' => 7, 'chat' => ['id' => -100123]]], $status)]);
}
it('sends one explicit simulated test per bot while automatic notices stay paused', function ($module) {
    [$u,$b,$d] = manualTestFixture($module);
    manualTestFake($b);
    $input = manualTestInput($b, $d);
    $a = app(SendTelegramTestMessageAction::class);
    expect($a->execute($u, $input)['status'])->toBe('SENT');
    $a->execute($u, $input);
    expect($b->fresh()->is_enabled)->toBeFalse()->and($d->fresh()->is_enabled)->toBeFalse()->and(TelegramDispatch::count())->toBe(0);
    expect(TelegramConfigurationChange::where('kind', 'BOT_TEST_REQUEST')->sole()->snapshot)->toMatchArray(['module' => $module, 'bot_name' => $b->name, 'destination_name' => $d->name]);
    expect(TelegramConfigurationChange::where('kind', 'BOT_TEST_REQUEST')->sole()->snapshot['message'])->toBe(SendTelegramTestMessageAction::text($module));
    expect(TelegramConfigurationChange::where('kind', 'BOT_TEST_ATTEMPT')->count())->toBe(1);
    expect(TelegramConfigurationChange::where('kind', 'BOT_TEST_REQUEST')->count())->toBe(1)->and(TelegramConfigurationChange::where('kind', 'BOT_TEST_RESULT')->count())->toBe(1);
    Http::assertSent(fn ($r) => $r['chat_id'] === '-100123' && str_contains($r['text'], 'PRUEBA') && str_contains($r['text'], TelegramSettingsQuery::MODULES[$module]) && ! str_contains($r['text'], 'Bs ') && $r['protect_content'] === true);
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
    expect(json_encode(TelegramConfigurationChange::whereIn('kind', ['BOT_TEST_REQUEST', 'BOT_TEST_RESULT'])->get()->toArray()))->not->toContain($b->token)->not->toContain($d->chat_id);
})->with(['CAJAS', 'PRESTAMOS', 'TARJETAS', 'ACCESORIOS']);
it('does not send again on reentry while the first response is pending and sends outside TX', function () {
    [$u,$b,$d] = manualTestFixture();
    $input = manualTestInput($b, $d);
    $a = app(SendTelegramTestMessageAction::class);
    Http::fake(['https://api.telegram.org/bot'.$b->token.'/sendMessage' => function () use ($a, $u, $input) {
        expect(TelegramConfigurationChange::where('kind', 'BOT_TEST_ATTEMPT')->count())->toBe(1);
        expect(DB::transactionLevel())->toBe(0)->and($a->execute($u, $input)['status'])->toBe('PENDING');

        return Http::response(['ok' => true, 'result' => ['message_id' => 7, 'chat' => ['id' => -100123]]]);
    }]);
    expect($a->execute($u, $input)['status'])->toBe('SENT');
    Http::assertSentCount(1);
});
it('records safe failures and uncertainty without automatic retries', function ($case) {
    [$u,$b,$d] = manualTestFixture();
    $calls = 0;
    if ($case === 'timeout') {
        Http::fake(['https://api.telegram.org/bot'.$b->token.'/sendMessage' => function () use (&$calls) {
            $calls++;
            throw new ConnectionException('SECRET transport detail');
        }]);
    } else {
        manualTestFake($b, match ($case) {
            '429' => ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 120]],'403' => ['ok' => false, 'error_code' => 403],'wrong_chat' => ['ok' => true, 'result' => ['message_id' => 7, 'chat' => ['id' => 999]]],default => ['ok' => false, 'error_code' => 500]
        }, $case === 'wrong_chat' ? 200 : (int) $case);
    }
    $input = manualTestInput($b, $d);
    $a = app(SendTelegramTestMessageAction::class);
    $r = $a->execute($u, $input);
    $a->execute($u, $input);
    expect($r['status'])->toBe(in_array($case, ['timeout', 'wrong_chat']) ? 'UNCERTAIN' : 'REJECTED');
    if ($case === 'timeout') {
        expect($calls)->toBe(1);
    } else {
        Http::assertSentCount(1);
    } Queue::assertNothingPushed();
    expect(json_encode(TelegramConfigurationChange::where('kind', 'BOT_TEST_RESULT')->sole()->snapshot))->not->toContain('SECRET');
})->with(['429', '403', '500', 'timeout', 'wrong_chat']);
it('rejects missing confirmation stale revisions unverified connection and emergency maintenance', function ($case) {
    [$u,$b,$d] = manualTestFixture();
    $input = manualTestInput($b, $d);
    match ($case) {
        'confirmation' => $input['confirmed'] = false,'bot' => $b->increment('revision'),'destination' => $d->increment('revision'),'check' => TelegramConfigurationChange::where('kind', 'DESTINATION_CHECK')->delete(),'global' => config(['telegram.sending_enabled' => false]),'unassigned' => $input['destination_id'] = 999
    };
    expect(fn () => app(SendTelegramTestMessageAction::class)->execute($u, $input))->toThrow(ValidationException::class);
    Http::assertNothingSent();
    expect(TelegramConfigurationChange::where('kind', 'BOT_TEST_REQUEST')->count())->toBe(0);
})->with(['confirmation', 'bot', 'destination', 'check', 'global', 'unassigned']);
it('forbids cashier manager and inactive administrator even with permission', function ($role) {
    [$u,$b,$d] = manualTestFixture();
    if ($role === 'inactive') {
        $u->forceFill(['is_active' => false])->save();
    } else {
        $u->syncRoles([$role]);
        $u->givePermissionTo('notificaciones.telegram.configurar');
    }
    expect(fn () => app(SendTelegramTestMessageAction::class)->execute($u, manualTestInput($b, $d)))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
})->with(['cajera', 'encargado', 'inactive']);
it('rejects UUID changes and throttles fresh requests without preventing a safe replay', function () {
    [$u,$b,$d] = manualTestFixture();
    manualTestFake($b);
    $a = app(SendTelegramTestMessageAction::class);
    $input = manualTestInput($b, $d);
    $a->execute($u, $input);
    expect(fn () => $a->execute($u, array_replace($input, ['destination_id' => 999])))->toThrow(ValidationException::class);
    expect(fn () => $a->execute($u, manualTestInput($b, $d)))->toThrow(ValidationException::class);
    $a->execute($u, $input);
    Http::assertSentCount(1);
    test()->travel(61)->seconds();
    $a->execute($u, manualTestInput($b, $d));
    Http::assertSentCount(2);
});
it('cannot perform HTTP inside a surrounding business transaction', function () {
    [$u,$b,$d] = manualTestFixture();
    expect(fn () => DB::transaction(fn () => app(SendTelegramTestMessageAction::class)->execute($u, manualTestInput($b, $d))))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});
it('mounts confirmation and reports the simulated test through Livewire', function () {
    [$u,$b,$d] = manualTestFixture();
    manualTestFake($b);
    $p = Livewire::test(TelegramSettings::class)->assertSee('Mensaje de prueba')->mountAction('testMessage', ['id' => $b->id]);
    $p->setActionData(['destination_id' => $d->id, 'confirmed' => true])->callMountedAction()->assertHasNoActionErrors()->assertSee('Prueba enviada');
    expect($b->fresh()->is_enabled)->toBeFalse();
    Http::assertSentCount(1);
});

it('preserves an already active bot and its enabled destination without creating automatic events', function () {
    [$u,$b,$d] = manualTestFixture();
    $b->update(['is_enabled' => true]);
    $d->update(['is_enabled' => true]);
    manualTestFake($b);
    app(SendTelegramTestMessageAction::class)->execute($u, manualTestInput($b, $d));
    expect($b->fresh()->is_enabled)->toBeTrue()->and($d->fresh()->is_enabled)->toBeTrue()->and(TelegramDispatch::count())->toBe(0);
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
});
it('rejects foreign destinations and revoked destination recipients', function ($case) {
    [$u,$b,$d] = manualTestFixture();
    if ($case === 'foreign') {
        $other = $b->replicate();
        $other->module = 'PRESTAMOS';
        $other->token_fingerprint = hash('sha256', 'other');
        $other->save();
        $d->update(['bot_id' => $other->id]);
    } else {
        $owner = User::factory()->create();
        $owner->assignRole($case === 'cashier' ? 'cajera' : 'encargado');
        if ($case === 'inactive') {
            $owner->forceFill(['is_active' => false])->save();
        }
        $d->update(['responsible_id' => $owner->id]);
    }
    expect(fn () => app(SendTelegramTestMessageAction::class)->execute($u, manualTestInput($b, $d)))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with(['foreign', 'cashier', 'inactive']);
it('rejects replay by another administrator and preserves a consumed pending UUID after interruption', function () {
    [$u,$b,$d] = manualTestFixture();
    $input = manualTestInput($b, $d);
    manualTestFake($b);
    $fail = true;
    TelegramConfigurationChange::creating(function ($row) use (&$fail) {
        if ($fail && $row->kind === 'BOT_TEST_RESULT') {
            $fail = false;
            throw new RuntimeException('simulated interruption after HTTP');
        }
    });
    $a = app(SendTelegramTestMessageAction::class);
    expect(fn () => $a->execute($u, $input))->toThrow(RuntimeException::class);
    expect($a->execute($u, $input)['status'])->toBe('PENDING');
    $other = User::factory()->create();
    $other->assignRole('administrador');
    expect(fn () => $a->execute($other, $input))->toThrow(AuthorizationException::class);
    Http::assertSentCount(1);
});

it('does not send when the modal is cancelled or confirmation is missing', function () {
    [$u,$b,$d] = manualTestFixture();
    $page = Livewire::test(TelegramSettings::class)->mountAction('testMessage', ['id' => $b->id])->unmountAction();
    $page->mountAction('testMessage', ['id' => $b->id])->setActionData(['destination_id' => $d->id, 'confirmed' => false])->callMountedAction()->assertHasActionErrors(['confirmed']);
    Http::assertNothingSent();
    expect(TelegramConfigurationChange::where('kind', 'BOT_TEST_REQUEST')->count())->toBe(0);
});
