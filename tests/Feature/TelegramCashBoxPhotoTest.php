<?php

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Notificaciones\Services\CashBoxTelegramPhoto;
use App\Infrastructure\Telegram\TelegramMessageClient;
use App\Jobs\SendTelegramDelivery;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

it('selects the existing image for each cash box family and freezes its bytes', function ($family, $filename) {
    $box = new CashBox(['type' => $family]);
    $photo = app(CashBoxTelegramPhoto::class)->snapshot($box, '🟢 CAJA ABIERTA\nCaja: prueba');
    expect($photo['filename'])->toBe($filename)
        ->and(base64_decode($photo['content'], true))->toBe(file_get_contents(base_path('logos/'.$filename)))
        ->and($photo['sha256'])->toBe(hash('sha256', base64_decode($photo['content'], true)))
        ->and($photo['caption'])->toContain('CAJA ABIERTA');
})->with([['BCP', 'agente bcp cajas.jpg'], ['TIGO_MONEY', 'tigo money caja principa PTM.png']]);

it('does not invent a bank image for an unknown cash box type', function () {
    expect(app(CashBoxTelegramPhoto::class)->snapshot(new CashBox(['type' => 'CUSTOM_UNKNOWN']), 'Aviso'))->toBeNull();
});

it('uploads one photo with caption under the same HTTPS and destination checks', function () {
    Http::preventStrayRequests();
    config(['telegram.sending_enabled' => true, 'telegram.ca_bundle' => null]);
    $photo = app(CashBoxTelegramPhoto::class)->snapshot(new CashBox(['type' => 'BCP']), '🟢 CAJA ABIERTA');
    Http::fake(['https://api.telegram.org/botfake/sendPhoto' => function ($request, $options) use ($photo) {
        expect($options['verify'])->toBeTrue()->and($options['allow_redirects'])->toBeFalse();
        $parts = collect($request->data())->pluck('contents', 'name');
        expect($parts['caption'])->toBe($photo['caption'])->and($parts['chat_id'])->toBe('123');
        expect($request->hasFile('photo'))->toBeTrue();

        return Http::response(['ok' => true, 'result' => ['message_id' => 10, 'chat' => ['id' => 123]]]);
    }]);
    expect(app(TelegramMessageClient::class)->send('fake', '123', 'Detalle', $photo)['outcome'])->toBe('SENT');
    Http::assertSentCount(1);
});

it('never falls back to a second text message after an uncertain photo upload', function () {
    Http::preventStrayRequests();
    config(['telegram.sending_enabled' => true, 'telegram.ca_bundle' => null]);
    $photo = app(CashBoxTelegramPhoto::class)->snapshot(new CashBox(['type' => 'BCP']), 'CAJA ABIERTA');
    Http::fake(['https://api.telegram.org/botfake/sendPhoto' => Http::failedConnection()]);
    expect(app(TelegramMessageClient::class)->send('fake', '123', 'Detalle', $photo)['outcome'])->toBe('UNCERTAIN');
    Http::assertSentCount(1);
});

it('rejects changed photo bytes or an oversized caption before HTTP', function ($invalid) {
    Http::preventStrayRequests();
    config(['telegram.sending_enabled' => true]);
    $photo = app(CashBoxTelegramPhoto::class)->snapshot(new CashBox(['type' => 'BCP']), 'CAJA ABIERTA');
    $photo[$invalid] = $invalid === 'caption' ? str_repeat('x', 1025) : base64_encode('corrupto');
    expect(app(TelegramMessageClient::class)->send('fake', '123', 'Detalle', $photo)['code'])->toBe('INVALID_MEDIA');
    Http::assertNothingSent();
})->with(['caption', 'content']);

it('keeps the transport pause effective for photos', function () {
    Http::preventStrayRequests();
    config(['telegram.sending_enabled' => false]);
    $photo = app(CashBoxTelegramPhoto::class)->snapshot(new CashBox(['type' => 'BCP']), 'CAJA ABIERTA');
    expect(app(TelegramMessageClient::class)->send('fake', '123', 'Detalle', $photo)['outcome'])->toBe('PAUSED');
    Http::assertNothingSent();
});

it('keeps a long closing caption bounded without losing totals or its pending status', function () {
    $lines = ['🟠 CIERRE PENDIENTE · Cierre registrado', 'Caja: '.str_repeat('a', 80), 'Responsable: '.str_repeat('b', 80), 'Turno: Tarde', 'Cierre #123 · Sesión #123 · 30/10/2026 20:00 · Bolivia'];
    for ($i = 0; $i < 50; $i++) {
        $lines[] = str_repeat('Cuenta', 12).' · esperado Bs 100 · contado Bs 99';
    }
    $lines = [...$lines, 'Esperado: Bs 5000', 'Contado: Bs 4950', 'Faltante registrado: Bs 50', 'Sobrante registrado: Bs 0', 'Capital operativo de referencia: Bs 4900', 'Saldo posterior: Bs 4900', 'Liquidación realizada: Bs 50', 'Estado: Pendiente de autorización administrativa'];
    $caption = app(CashBoxTelegramPhoto::class)->caption(implode("\n", $lines));
    expect(mb_strlen($caption))->toBeLessThanOrEqual(1024)->and($caption)->toContain('Faltante registrado: Bs 50', 'Liquidación realizada: Bs 50', 'Estado: Pendiente de autorización', 'Detalle completo');
});

it('keeps API rejection and destination mismatch handling for photo uploads', function ($body, $status, $outcome) {
    Http::preventStrayRequests();
    config(['telegram.sending_enabled' => true, 'telegram.ca_bundle' => null]);
    $photo = app(CashBoxTelegramPhoto::class)->snapshot(new CashBox(['type' => 'BCP']), 'CAJA ABIERTA');
    Http::fake(['https://api.telegram.org/botfake/sendPhoto' => Http::response($body, $status)]);
    expect(app(TelegramMessageClient::class)->send('fake', '123', 'Detalle', $photo)['outcome'])->toBe($outcome);
    Http::assertSentCount(1);
})->with([
    [['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 90]], 429, 'RETRYABLE'],
    [['ok' => false, 'error_code' => 403], 403, 'PERMANENT'],
    [['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 999]]], 200, 'UNCERTAIN'],
]);

it('falls back to text when an existing logo becomes unreadable or corrupt', function ($unreadable) {
    $file = File::shouldReceive('get')->once()->with(base_path('logos/agente bcp cajas.jpg'));
    if ($unreadable) {
        $file->andThrow(new FileNotFoundException('Archivo no disponible'));
    } else {
        $file->andReturn('not an image');
    }
    expect(app(CashBoxTelegramPhoto::class)->snapshot(new CashBox(['type' => 'BCP']), 'CAJA ABIERTA'))->toBeNull();
})->with([false, true]);

it('gives photo uploads a bounded longer timeout while keeping text requests unchanged', function ($withPhoto, $timeout) {
    Http::preventStrayRequests();
    config(['telegram.sending_enabled' => true, 'telegram.ca_bundle' => null]);
    $photo = $withPhoto ? app(CashBoxTelegramPhoto::class)->snapshot(new CashBox(['type' => 'TIGO_MONEY']), 'EJEMPLO · CIERRE') : null;
    $observed = [];
    $method = $withPhoto ? 'sendPhoto' : 'sendMessage';
    Http::fake(['https://api.telegram.org/botfake/'.$method => function ($request, $options) use (&$observed) {
        $observed = $options;

        return Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 123]]]);
    }]);
    expect(app(TelegramMessageClient::class)->send('fake', '123', 'EJEMPLO', $photo)['outcome'])->toBe('SENT');
    expect($observed['timeout'])->toBe($timeout)->and($observed['connect_timeout'])->toBe(3)
        ->and($timeout)->toBeLessThan((new SendTelegramDelivery(1))->timeout);
    Http::assertSentCount(1);
})->with([[false, 8], [true, 20]]);
