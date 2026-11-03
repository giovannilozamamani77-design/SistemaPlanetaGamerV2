<?php

use App\Domain\Notificaciones\Actions\CheckTelegramConnectionAction;
use App\Infrastructure\Telegram\TelegramMessageClient;
use App\Infrastructure\Telegram\TelegramReadClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('preserves HTTPS verification and applies an explicit trusted bundle to both clients', function ($send, $custom) {
    Http::preventStrayRequests();
    config(['telegram.sending_enabled' => true, 'telegram.ca_bundle' => $custom ? __FILE__ : null]);
    $token = '123456:'.str_repeat('A', 35);
    $method = $send ? 'sendMessage' : 'getMe';
    Http::fake(['https://api.telegram.org/bot'.$token.'/'.$method => function ($request, $options) use ($send, $custom) {
        expect($options['verify'])->toBe($custom ? __FILE__ : true)->and($options['allow_redirects'])->toBeFalse();

        return Http::response(['ok' => true, 'result' => $send ? ['message_id' => 1, 'chat' => ['id' => -100123]] : ['id' => 123456, 'is_bot' => true]]);
    }]);
    if ($send) {
        expect(app(TelegramMessageClient::class)->send($token, '-100123', 'Prueba simulada')['outcome'])->toBe('SENT');
    } else {
        expect(app(TelegramReadClient::class)->read($token, 'getMe')['ok'])->toBeTrue();
    }
    Http::assertSentCount(1);
})->with([[false, false], [false, true], [true, false], [true, true]]);
it('rejects nonexistent bundles and never allows false to disable HTTPS verification', function ($value) {
    Http::preventStrayRequests();
    config(['telegram.sending_enabled' => true, 'telegram.ca_bundle' => $value]);
    expect(app(TelegramReadClient::class)->read('fake', 'getMe')['code'])->toBe('CA_BUNDLE_UNAVAILABLE');
    expect(app(TelegramMessageClient::class)->send('fake', '1', 'Simulado')['code'])->toBe('CA_BUNDLE_UNAVAILABLE');
    Http::assertNothingSent();
})->with(['C:/nonexistent-c333-ca.pem', false]);
it('distinguishes certificate failures without exposing credentials or exception URLs', function () {
    Http::preventStrayRequests();
    config(['telegram.ca_bundle' => null]);
    $token = '123456:'.str_repeat('A', 35);
    Http::fake(['https://api.telegram.org/bot'.$token.'/getMe' => fn () => throw new ConnectionException('cURL error 60: certificate failed https://api.telegram.org/bot'.$token.'/getMe')]);
    $result = app(TelegramReadClient::class)->read($token, 'getMe');
    expect($result)->toBe(['ok' => false, 'code' => 'SSL_CERTIFICATE_FAILED']);
    expect(CheckTelegramConnectionAction::message($result))->toContain('certificado')->not->toContain($token)->not->toContain('https://');
});
