<?php

use App\Domain\Accesorios\Actions\InstallDefaultAccessoryCategoriesAction;
use App\Domain\Accesorios\Actions\RefreshPricingReferenceAction;
use App\Domain\Envios\Actions\SyncExchangeReferenceAction;
use App\Domain\Notificaciones\Services\TelegramDeliveryPublisher;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('historial:procesar')->everyFiveMinutes()->timezone('America/La_Paz')->withoutOverlapping();

Artisan::command('envios:actualizar-referencia', function () {
    try {
        $updated = app(SyncExchangeReferenceAction::class)->execute();
        $this->info($updated ? 'Referencia actualizada. Las tasas comerciales no se modificaron.' : 'Referencia aún vigente o actualización deshabilitada.');
    } catch (Throwable $exception) {
        report($exception);
        $this->error('No se pudo actualizar la referencia; no se cambiaron las tasas.');

        return 1;
    }

    return 0;
})->purpose('Actualiza la referencia BRL/BOB sin modificar costos ni tasas comerciales');
if (config('envios.enabled', false)) {
    Schedule::command('envios:actualizar-referencia')->hourly()->timezone('America/La_Paz')->withoutOverlapping();
}

Artisan::command('accesorios:actualizar-referencia', function () {
    try {
        $quote = app(RefreshPricingReferenceAction::class)->execute();
        $this->info($quote ? 'Referencia disponible; precios automáticos revisados.' : 'Actualización no necesaria o todavía sin configurar.');
    } catch (Throwable $exception) {
        report($exception);
        $this->error('No se pudo actualizar; se conserva la última referencia válida.');

        return 1;
    }

    return 0;
})->purpose('Actualiza referencia P2P y precios automáticos autorizados de teléfonos nuevos');

Schedule::command('accesorios:actualizar-referencia')->everyThirtyMinutes()->timezone('America/La_Paz')->withoutOverlapping();

Artisan::command('accesorios:categorias-iniciales {--user=}', function () {
    $id = filter_var($this->option('user'), FILTER_VALIDATE_INT);
    if (! $id || $id < 1) {
        $this->error('Indica --user con el administrador responsable.');

        return 1;
    }
    $actor = User::find($id);
    if (! $actor) {
        $this->error('Administrador no encontrado.');

        return 1;
    }
    try {
        $result = app(InstallDefaultAccessoryCategoriesAction::class)->execute($actor);
    } catch (AuthorizationException) {
        $this->error('El usuario no puede gestionar inventario.');

        return 1;
    }
    $this->info('Categorías agregadas: '.$result['created'].'; existentes conservadas: '.$result['existing']);
    if ($result['conflicts']) {
        $this->warn('Controles existentes conservados; revisar: '.implode(', ', $result['conflicts']));
    }

    return 0;
})->purpose('Agrega categorías iniciales de Accesorios sin reemplazar las existentes');

Artisan::command('telegram:dispatch-due {--limit=100}', function () {
    $count = app(TelegramDeliveryPublisher::class)->due((int) $this->option('limit'));
    $this->info('Trabajos publicados: '.$count.'. '.(config('telegram.sending_enabled', false) ? 'Canal autorizado.' : 'Envíos pausados.'));

    return 0;
})->purpose('Recupera intenciones Telegram sin duplicar entregas; no activa envíos');

Schedule::command('telegram:dispatch-due')->everyMinute()->withoutOverlapping()->when(fn () => config('telegram.sending_enabled', false));

// Optional bounded worker for shared hosting; no daemon or external supervisor.
Schedule::command('queue:work telegram --queue=telegram --stop-when-empty --max-time=45 --timeout=30 --tries=1')
    ->everyMinute()->withoutOverlapping(3)
    ->when(fn () => config('telegram.scheduled_worker_enabled', false) && config('telegram.sending_enabled', false));
