<?php

use App\Domain\Notificaciones\Actions\CheckTelegramConnectionAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Filament\Pages\Notificaciones\TelegramSettings;
use App\Models\User;
use Database\Seeders\Notificaciones\TelegramPermissionsSeeder;
use Database\Seeders\Usuarios\UsuariosPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);
function connectionFixture(): array
{
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    test()->seed([UsuariosPermissionsSeeder::class, TelegramPermissionsSeeder::class]);
    Http::preventStrayRequests();
    $u = User::factory()->create();
    $u->assignRole('administrador');
    $b = TelegramBotSetting::create(['module' => 'CAJAS', 'name' => 'Test', 'token' => '123456:'.str_repeat('A', 35), 'token_fingerprint' => hash('sha256', 'test'), 'revision' => 1, 'is_enabled' => false, 'updated_by' => $u->id]);
    $d = TelegramDestination::create(['bot_id' => $b->id, 'responsible_id' => $u->id, 'name' => 'Test', 'chat_id' => '-100123', 'kind' => 'GROUP', 'revision' => 1, 'is_enabled' => false, 'updated_by' => $u->id]);

    return [$u, $b, $d];
}
function connectionInput($id, $kind = 'BOT'): array
{
    return ['id' => $id, 'kind' => $kind, 'expected_revision' => 1, 'bot_revision' => 1, 'request_key' => (string) Str::uuid()];
}
it('checks a bot once without messages and preserves paused settings', function () {
    [$u,$b] = connectionFixture();
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'test_bot']])]);
    $v = connectionInput($b->id);
    $a = app(CheckTelegramConnectionAction::class);
    expect($a->execute($u, $v)['status'])->toBe('CONNECTED');
    $a->execute($u, $v);
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), '/getMe'));
    expect($b->fresh()->is_enabled)->toBeFalse()->and(TelegramConfigurationChange::count())->toBe(1);
    expect(json_encode(TelegramConfigurationChange::sole()->toArray()))->not->toContain($b->token);
});
it('checks private group access using only read methods', function () {
    [$u,$b,$d] = connectionFixture();
    Http::fakeSequence()->push(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true]])->push(['ok' => true, 'result' => ['id' => -100123, 'type' => 'supergroup']])->push(['ok' => true, 'result' => ['user' => ['id' => 123456], 'status' => 'member']]);
    expect(app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($d->id, 'DESTINATION'))['status'])->toBe('CONNECTED');
    Http::assertSentCount(3);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/getChatMember'));
    expect($d->fresh()->is_enabled)->toBeFalse();
});
it('rejects public or mismatched destinations', function ($chat) {
    [$u,$b,$d] = connectionFixture();
    Http::fakeSequence()->push(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true]])->push(['ok' => true, 'result' => $chat]);
    expect(app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($d->id, 'DESTINATION'))['status'])->toBe('FAILED');
    Http::assertSentCount(2);
})->with([[['id' => -100123, 'type' => 'supergroup', 'username' => 'public']], [['id' => -100123, 'type' => 'channel']], [['id' => -999, 'type' => 'group']]]);
it('sanitizes API failures', function ($status) {
    [$u,$b] = connectionFixture();
    Http::fake(['*' => Http::response(['ok' => false, 'description' => $b->token], $status)]);
    $r = app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($b->id));
    expect($r['status'])->toBe('FAILED');
    expect(json_encode($r))->not->toContain($b->token);
})->with([401, 403, 429, 500]);
it('rejects unauthorized and stale checks before HTTP', function () {
    [$u,$b] = connectionFixture();
    $v = connectionInput($b->id);
    $v['expected_revision'] = 2;
    expect(fn () => app(CheckTelegramConnectionAction::class)->execute($u, $v))->toThrow(ValidationException::class);
    $u->forceFill(['is_active' => false])->save();
    expect(fn () => app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($b->id)))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});
it('discards a response if settings change during the request', function () {
    [$u,$b] = connectionFixture();
    Http::fake(function () use ($b) {
        $b->increment('revision');

        return Http::response(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true]]);
    });
    expect(fn () => app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($b->id)))->toThrow(ValidationException::class);
    expect(TelegramConfigurationChange::count())->toBe(0);
});

it('handles transport failures without exposing the credential', function () {
    [$u,$b] = connectionFixture();
    Http::fake(fn () => throw new ConnectionException('secret '.$b->token));
    $r = app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($b->id));
    expect($r['code'])->toBe('CONNECTION_FAILED');
    expect(json_encode($r))->not->toContain($b->token);
});
it('rejects invalid bot identities', function ($identity) {
    [$u,$b] = connectionFixture();
    Http::fake(['*' => Http::response(['ok' => true, 'result' => $identity])]);
    expect(app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($b->id))['status'])->toBe('FAILED');
})->with([[['id' => 999, 'is_bot' => true]], [['id' => 123456, 'is_bot' => false]]]);
it('rejects inaccessible group membership', function ($member) {
    [$u,$b,$d] = connectionFixture();
    Http::fakeSequence()->push(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true]])->push(['ok' => true, 'result' => ['id' => -100123, 'type' => 'group']])->push(['ok' => true, 'result' => ['user' => ['id' => 123456]] + $member]);
    expect(app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($d->id, 'DESTINATION'))['status'])->toBe('FAILED');
})->with([[['status' => 'left']], [['status' => 'kicked']], [['status' => 'restricted', 'is_member' => true, 'can_send_messages' => false]]]);
it('accepts a private chat without claiming identity verification', function () {
    [$u,$b,$d] = connectionFixture();
    $d->update(['kind' => 'PRIVATE', 'chat_id' => '123']);
    Http::fakeSequence()->push(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true]])->push(['ok' => true, 'result' => ['id' => 123, 'type' => 'private', 'username' => 'person']]);
    expect(app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($d->id, 'DESTINATION'))['status'])->toBe('CONNECTED');
    Http::assertSentCount(2);
});
it('opens check modal without HTTP and checks only on confirmation', function () {
    [$u,$b] = connectionFixture();
    test()->actingAs($u);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('inicio'));
    $page = Livewire\Livewire::test(TelegramSettings::class)->mountAction('check', ['kind' => 'BOT', 'id' => $b->id])->assertActionDataSet(['expected_revision' => 1]);
    Http::assertNothingSent();
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true]])]);
    $page->callMountedAction()->assertHasNoActionErrors()->assertSee('Acceso comprobado');
    Http::assertSentCount(1);
    $b->increment('revision');
    $page->refresh()->assertSee('Sin comprobar');
});
it('rejects changed UUID payload and shared bot identities', function () {
    [$u,$b] = connectionFixture();
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true]])]);
    $v = connectionInput($b->id);
    app(CheckTelegramConnectionAction::class)->execute($u, $v);
    $other = TelegramBotSetting::create(['module' => 'TARJETAS', 'name' => 'Other', 'token' => '123456:'.str_repeat('B', 35), 'token_fingerprint' => hash('sha256', 'other'), 'revision' => 1, 'is_enabled' => false, 'updated_by' => $u->id]);
    expect(fn () => app(CheckTelegramConnectionAction::class)->execute($u, array_replace($v, ['id' => $other->id])))->toThrow(ValidationException::class);
    expect(app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($other->id))['code'])->toBe('SHARED_BOT');
});

it('rejects malformed and redirected API responses', function ($body, $status) {
    [$u,$b] = connectionFixture();
    Http::fake(['*' => Http::response($body, $status)]);
    expect(app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($b->id))['status'])->toBe('FAILED');
})->with([['not json', 200], [['ok' => true, 'result' => 'invalid'], 200], [[], 302]]);
it('rate limits repeated manual checks without another request', function () {
    [$u,$b] = connectionFixture();
    RateLimiter::hit('telegram-check:'.$u->id, 60);
    for ($i = 0; $i < 9; $i++) {
        RateLimiter::hit('telegram-check:'.$u->id, 60);
    }
    expect(fn () => app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($b->id)))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});
it('rejects administrative permission revoked during a check', function () {
    [$u,$b] = connectionFixture();
    Http::fake(function () use ($u) {
        $u->forceFill(['is_active' => false])->save();

        return Http::response(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true]]);
    });
    expect(fn () => app(CheckTelegramConnectionAction::class)->execute($u, connectionInput($b->id)))->toThrow(AuthorizationException::class);
    expect(TelegramConfigurationChange::count())->toBe(0);
});
