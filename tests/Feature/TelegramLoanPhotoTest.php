<?php

use App\Domain\Notificaciones\Services\LoanTelegramPhoto;
use App\Domain\Notificaciones\Services\TelegramPhotoSnapshot;
use App\Infrastructure\Telegram\TelegramMessageClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

it('freezes the loan receipt logo and the ordered caption in the same photo snapshot', function () {
    $photo = app(LoanTelegramPhoto::class)->snapshot("🟢 PRÉSTAMOS · Préstamo registrado\nPréstamo #1\nCapital: Bs 200\nInterés: Bs 15\nTotal a pagar: Bs 215\nVencimiento: 15/10/2026");
    expect($photo['filename'])->toBe('logoReciboPrestamo.png')->and($photo['version'])->toBe(2);
    expect(base64_decode($photo['content'], true))->toBe(file_get_contents(base_path('logos/logoReciboPrestamo.png')));
    expect($photo['caption'])->toContain('Capital: Bs 200', 'Interés: Bs 15', 'Total a pagar: Bs 215', '15/10/2026');
});

it('keeps long renewals within the photo limit without truncating money or due dates', function () {
    $text = "🔄 PRÉSTAMOS · Renovación registrada\nPréstamo #123456789\nCliente: ".str_repeat('Cliente ', 15)."\nObjeto: ".str_repeat('Objeto ', 15)."\nDetalle: ".str_repeat('Descripción ', 60)."\nResponsable: ".str_repeat('Cajera ', 15)."\nFecha de operación: 16/10/2026 12:00 · Bolivia\nIMPORTES COBRADOS\nCapital conservado: Bs 200\nInterés cobrado: Bs 15\nMulta cobrada: Bs 40\nTotal cobrado: Bs 55\nFECHAS Y PLAZO\nPlazo seleccionado: 30 días · Periodos: 1\nDías añadidos: 30\nVencimiento anterior: 15/10/2026\nNuevo vencimiento: 14/11/2026\nInterés pendiente: Bs 30\nFecha de pago indicada arriba; el periodo añadido parte del vencimiento anterior.\nVer préstamo e historial: https://example.invalid/".str_repeat('a', 450);
    $photo = app(LoanTelegramPhoto::class)->snapshot($text);
    expect(mb_strlen($photo['caption']))->toBeLessThanOrEqual(1024);
    expect($photo['caption'])->toContain('Multa cobrada: Bs 40', 'Total cobrado: Bs 55', 'Interés pendiente: Bs 30', 'Vencimiento anterior: 15/10/2026', 'Nuevo vencimiento: 14/11/2026', 'Fecha de operación: 16/10/2026', 'Detalle completo');
});

it('sends a loan photo through one protected upload with no extra text message', function () {
    Http::preventStrayRequests();
    config(['telegram.sending_enabled' => true, 'telegram.ca_bundle' => null]);
    $photo = app(LoanTelegramPhoto::class)->snapshot('✅ PRÉSTAMOS · Pago y entrega registrados');
    Http::fake(['https://api.telegram.org/botfake/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 123]]])]);
    expect(app(TelegramMessageClient::class)->send('fake', '123', 'Detalle', $photo)['outcome'])->toBe('SENT');
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r->hasFile('photo', base64_decode($photo['content'], true)));
});

it('keeps the text notice possible when the loan logo is corrupt', function () {
    File::shouldReceive('get')->once()->with(base_path('logos/logoReciboPrestamo.png'))->andReturn('invalid');
    expect(app(LoanTelegramPhoto::class)->snapshot('Préstamo registrado'))->toBeNull();
});

it('never reads logo paths outside the known logo directory', function ($name) {
    File::shouldReceive('get')->never();
    expect(app(TelegramPhotoSnapshot::class)->fromLogo($name, 'Aviso'))->toBeNull();
})->with(['../.env', 'C:\\private.png', 'https://example.invalid/logo.png']);
