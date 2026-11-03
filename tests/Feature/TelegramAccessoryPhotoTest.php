<?php

use App\Domain\Notificaciones\Services\AccessoryTelegramPhoto;
use App\Infrastructure\Telegram\TelegramMessageClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

it('freezes the existing brand logo for each accessory notice type', function ($title) {
    $photo = app(AccessoryTelegramPhoto::class)->snapshot($title);
    expect($photo['filename'])->toBe('logoReciboPrestamo.png')->and($photo['version'])->toBe(2);
    expect(base64_decode($photo['content'], true))->toBe(file_get_contents(base_path('logos/logoReciboPrestamo.png')));
    expect($photo['caption'])->toBe($title);
})->with(['📱 ACCESORIOS · Venta de teléfonos registrada', '💵 ACCESORIOS · Pago de comisiones registrado', '🔴 ACCESORIOS · Pago de comisiones anulado']);

it('keeps long sales within the photo limit while preserving quantities discount and total', function () {
    $lines = ['📱 ACCESORIOS · Venta de teléfonos registrada', 'Venta #123', 'Responsable: '.str_repeat('Cajera ', 15), 'Fecha de operación: 30/10/2026 14:00 · Bolivia', 'Teléfonos: 10', 'Accesorios: 20'];
    for ($i = 0; $i < 8; $i++) {
        $lines[] = 'Teléfono · '.str_repeat('Modelo ', 14).' × 1 · final Bs 940 · descuento Bs 10';
    }
    $lines[] = '… y 22 líneas más en el recibo; los totales incluyen toda la venta.';
    $lines[] = 'Descuento total: Bs 100';
    $lines[] = 'Total cobrado: Bs 9800';
    $photo = app(AccessoryTelegramPhoto::class)->snapshot(implode("\n", $lines));
    expect(mb_strlen($photo['caption']))->toBeLessThanOrEqual(1024);
    expect($photo['caption'])->toContain('Teléfonos: 10', 'Accesorios: 20', 'Descuento total: Bs 100', 'Total cobrado: Bs 9800', 'Detalle completo')->not->toContain('22 líneas más');
});

it('keeps restored commissions and the original payment distinct in a reversal notice', function () {
    $message = "🔴 ACCESORIOS · Pago de comisiones anulado\nAnulación #3 · Pago #2\nBeneficiaria: Lili\nImporte restituido: Bs 20\nPendiente tras esta anulación: Bs 30\nNo representa un cobro ni un movimiento de cajas o fondos del sistema.";
    $photo = app(AccessoryTelegramPhoto::class)->snapshot($message);
    expect($photo['caption'])->toContain('Anulación #3 · Pago #2', 'Importe restituido: Bs 20', 'Pendiente tras esta anulación: Bs 30', 'No representa un cobro');
});

it('keeps the text notice possible if the brand logo cannot be read', function () {
    File::shouldReceive('get')->once()->with(base_path('logos/logoReciboPrestamo.png'))->andReturn('invalid');
    expect(app(AccessoryTelegramPhoto::class)->snapshot('Venta de teléfonos registrada'))->toBeNull();
});

it('does not add a second text message if an accessory photo upload is uncertain', function () {
    Http::preventStrayRequests();
    config(['telegram.sending_enabled' => true, 'telegram.ca_bundle' => null]);
    $photo = app(AccessoryTelegramPhoto::class)->snapshot('Venta de teléfonos registrada');
    Http::fake(['https://api.telegram.org/botfake/sendPhoto' => Http::failedConnection()]);
    expect(app(TelegramMessageClient::class)->send('fake', '123', 'Detalle', $photo)['outcome'])->toBe('UNCERTAIN');
    Http::assertSentCount(1);
});
