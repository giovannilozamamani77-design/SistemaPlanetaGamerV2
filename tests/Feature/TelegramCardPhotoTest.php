<?php

use App\Domain\Notificaciones\Services\CardTelegramPhoto;
use App\Infrastructure\Telegram\TelegramMessageClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

it('chooses the saved company logo for a purchase and the store logo for general events', function ($company, $filename) {
    $photo = app(CardTelegramPhoto::class)->snapshot('🛒 TARJETAS · Compra registrada', $company);
    expect($photo['filename'])->toBe($filename)->and(base64_decode($photo['content'], true))->toBe(file_get_contents(base_path('logos/'.$filename)));
    expect($photo['caption'])->toContain('Compra registrada')->and($photo['version'])->toBe(2);
})->with([
    ['ENTEL', 'entel.jpg'], ['TIGO', 'recaraga o cuentas tigo money.png'], ['VIVA', 'recarga viva.jpg'], [null, 'logoReciboPrestamo.png'], ['DESCONOCIDA', 'logoReciboPrestamo.png'],
]);

it('keeps long counts readable without dropping shortages reserves available cash or responsibility', function () {
    $lines = ['🔴 TARJETAS · Cierre registrado', 'Responsable: '.str_repeat('Cajera ', 15), 'Fecha de operación: 30/10/2026 20:00 · Bolivia', 'Conteo #123 · Sesión #45', 'Efectivo esperado: Bs 1000', 'Efectivo contado: Bs 1010', 'Faltante de efectivo: Bs 0', 'Sobrante de efectivo: Bs 10', 'Efectivo reservado: Bs 10', 'Efectivo disponible: Bs 1000', 'Tarjetas esperadas: 50 · Contadas: 49', 'Tarjetas faltantes: 2 · Sobrantes: 1', 'Tarjetas reservadas: 1 · Disponibles: 48', 'Faltante total registrado: Bs 20', 'Responsable del turno: Cajera anterior', 'Deuda automática registrada; no requiere confirmar el faltante.'];
    for ($i = 0; $i < 8; $i++) {
        $lines[] = 'TIGO · '.str_repeat('Corte ', 10).' · esperado 10 · contado 9 · faltan 1 · sobran 0';
    }
    $lines[] = '… y 92 cortes más en el historial; los totales incluyen todos los cortes.';
    $lines[] = 'Revisar reservas: sobrantes retenidos según su registro. No compensa faltantes ni aumenta el disponible.';
    $lines[] = 'Estado confirmado: Sesión cerrada';
    $photo = app(CardTelegramPhoto::class)->snapshot(implode("\n", $lines));
    expect(mb_strlen($photo['caption']))->toBeLessThanOrEqual(1024);
    expect($photo['caption'])->toContain('Efectivo reservado: Bs 10', 'Efectivo disponible: Bs 1000', 'Faltante total registrado: Bs 20', 'Responsable del turno: Cajera anterior', 'Estado confirmado: Sesión cerrada', 'Detalle completo')->not->toContain('92 cortes más');
});

it('sends one card photo with no extra text message', function () {
    Http::preventStrayRequests();
    config(['telegram.sending_enabled' => true, 'telegram.ca_bundle' => null]);
    $photo = app(CardTelegramPhoto::class)->snapshot('🛒 TARJETAS · Compra registrada', 'TIGO');
    Http::fake(['https://api.telegram.org/botfake/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 123]]])]);
    expect(app(TelegramMessageClient::class)->send('fake', '123', 'Detalle', $photo)['outcome'])->toBe('SENT');
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r->hasFile('photo', base64_decode($photo['content'], true)));
});

it('keeps text available if the selected card logo cannot be decoded', function () {
    File::shouldReceive('get')->once()->with(base_path('logos/entel.jpg'))->andReturn('invalid');
    expect(app(CardTelegramPhoto::class)->snapshot('Compra registrada', 'ENTEL'))->toBeNull();
});
