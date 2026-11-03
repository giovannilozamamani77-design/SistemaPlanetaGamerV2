<?php

namespace App\Domain\Notificaciones\Queries;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Notificaciones\Data\TelegramEventCatalog;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Services\AccessoryTelegramPhoto;
use App\Domain\Notificaciones\Services\CardTelegramPhoto;
use App\Domain\Notificaciones\Services\CashBoxTelegramPhoto;
use App\Domain\Notificaciones\Services\LoanTelegramPhoto;
use App\Domain\Notificaciones\Services\TelegramTemplateRenderer;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TelegramEventsQuery
{
    public function forModule(User $actor, string $module): array
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        $catalog = app(TelegramEventCatalog::class)->events($module);
        $bot = TelegramBotSetting::select(['id', 'name', 'module', 'revision', 'is_enabled'])->where('module', $module)->first();
        $destinations = $bot ? TelegramDestination::with('responsible:id,name,is_active', 'responsible.roles')->where('bot_id', $bot->id)->orderBy('name')->orderBy('id')->get() : collect();
        $settings = $bot ? TelegramEventSetting::with(['destinations' => fn ($q) => $q->select(['telegram_destinations.id', 'bot_id', 'responsible_id', 'name', 'revision']), 'destinations.responsible.roles'])->where('bot_id', $bot->id)->get()->keyBy('event_code') : collect();
        $rows = [];
        foreach ($catalog as $code => $entry) {
            $setting = $settings->get($code);
            $review = $setting && (int) $setting->bot_revision !== (int) $bot->revision;
            foreach ($setting?->destinations ?? [] as $dest) {
                $review = $review || (int) $dest->bot_id !== $bot->id || (int) $dest->revision !== (int) $dest->pivot->destination_revision || ! $this->eligible($dest);
            }
            $rows[$code] = $entry + ['connected' => app(TelegramEventCatalog::class)->connected($module, $code), 'prepared' => $setting?->prepared ?? false, 'review_required' => (bool) $review, 'destinations' => $setting?->destinations->pluck('name')->all() ?? []];
        }

        return ['bot' => $bot, 'events' => $rows, 'destinations' => $destinations->filter(fn ($d) => $this->eligible($d))];
    }

    public function form(User $actor, string $module, string $code): array
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        app(TelegramEventCatalog::class)->event($module, $code);
        $data = $this->forModule($actor, $module);
        $bot = $data['bot'];
        if (! $bot) {
            throw ValidationException::withMessages(['event_code' => 'Configura primero el bot de este módulo.']);
        }
        $setting = TelegramEventSetting::with('destinations:id')->where('bot_id', $bot->id)->where('event_code', $code)->first();

        return ['module' => $module, 'event_code' => $code, 'prepared' => $setting?->prepared ?? false, 'destination_ids' => $setting?->destinations->pluck('id')->all() ?? [], 'destination_revisions' => $data['destinations']->pluck('revision', 'id')->all(), 'expected_revision' => $setting?->revision ?? 0, 'bot_revision' => $bot->revision, 'request_key' => (string) Str::uuid()];
    }

    public function destinationOptions(User $actor, string $module): array
    {
        return $this->forModule($actor, $module)['destinations']->mapWithKeys(fn ($d) => [$d->id => $d->name.' · '.$d->responsible->name])->all();
    }

    public function preview(User $actor, string $module, string $code): array
    {
        app(TelegramSettingsQuery::class)->authorize($actor);

        $preview = app(TelegramEventCatalog::class)->preview($module, $code);
        if ($module === 'CAJAS' && in_array($code, ['CJ_APERTURA', 'CJ_CIERRE'], true)) {
            $opening = $code === 'CJ_APERTURA';
            $preview['message'] = "EJEMPLO · Datos ficticios · No se envía\n\n".($opening ? '🟢 CAJA ABIERTA' : '🟠 CIERRE PENDIENTE')."\n\nCaja: Tablet Negra · BCP\nResponsable: Lili\nFecha: 30/10/2026 14:00 · Bolivia\n\n".($opening ? "SALDOS RECIBIDOS\n• Cuenta BCP: Bs 15000\n• Efectivo: Bs 2000\n• Yape: Bs 3000\nTotal contado: Bs 20000\nSin diferencias de recepción." : "Esperado: Bs 20050\nContado: Bs 20030\nFaltante registrado: Bs 20\nLiquidación realizada: Bs 30\nEstado: Pendiente de autorización administrativa");
            $preview['media'] = app(CashBoxTelegramPhoto::class)->snapshot(new CashBox(['type' => 'BCP']), $preview['message']);
            $preview['template_version'] = 2;
            $preview['image_label'] = 'Imagen de ejemplo BCP. Tigo Money utiliza su propia imagen.';
        }

        if ($module === 'PRESTAMOS' && in_array($code, ['PR_ALTA', 'PR_RENOVACION', 'PR_PAGO_ENTREGA'], true)) {
            $icon = match ($code) {
                'PR_ALTA' => '🟢', 'PR_RENOVACION' => '🔄', default => '✅'
            };
            $preview['message'] = "EJEMPLO · Datos ficticios · No se envía\n\n".$icon.' PRÉSTAMOS · '.$preview['title']."\nPréstamo #123\nCliente: Cliente de ejemplo\nObjeto: Garrafa\n\n".$preview['example']."\n\nResponsable: Lili\nFecha de operación: 30/10/2026 14:00 · Bolivia";
            $preview['media'] = app(LoanTelegramPhoto::class)->snapshot($preview['message']);
            $preview['template_version'] = 2;
            $preview['image_label'] = 'Logo utilizado en los recibos de Préstamos.';
        }

        if ($module === 'TARJETAS' && in_array($code, ['TJ_APERTURA', 'TJ_CIERRE', 'TJ_COMPRA', 'TJ_EFECTIVO'], true)) {
            $icon = match ($code) {
                'TJ_APERTURA' => '🟢', 'TJ_CIERRE' => '🔴', 'TJ_COMPRA' => '🛒', default => '🏦'
            };
            $preview['message'] = "EJEMPLO · Datos ficticios · No se envía\n\n".$icon.' TARJETAS · '.$preview['title']."\nResponsable: Lili\nFecha de operación: 30/10/2026 14:00 · Bolivia\n\n".$preview['example'];
            $preview['media'] = app(CardTelegramPhoto::class)->snapshot($preview['message'], $code === 'TJ_COMPRA' ? 'TIGO' : null);
            $preview['template_version'] = 2;
            $preview['image_label'] = $code === 'TJ_COMPRA' ? 'Compra de ejemplo Tigo; el aviso real usa la empresa registrada.' : 'Logo de Planeta Gamer para los avisos generales de Tarjetas.';
        }

        if ($module === 'ACCESORIOS' && in_array($code, ['AC_VENTA_TELEFONO', 'AC_COMISION_PAGO', 'AC_COMISION_ANULACION', 'AC_EFECTIVO_DEPOSITO', 'AC_EFECTIVO_ANULACION'], true)) {
            $icon = match ($code) {
                'AC_VENTA_TELEFONO' => '📱', 'AC_COMISION_PAGO' => '💵', 'AC_EFECTIVO_DEPOSITO' => '🏦', default => '🔴'
            };
            $preview['message'] = "EJEMPLO · Datos ficticios · No se envía\n\n".$icon.' ACCESORIOS · '.$preview['title']."\nResponsable: Lili\nFecha de operación: 30/10/2026 14:00 · Bolivia\n\n".$preview['example'];
            $preview['media'] = app(AccessoryTelegramPhoto::class)->snapshot($preview['message']);
            $preview['template_version'] = 2;
            $preview['image_label'] = 'Logo de Planeta Gamer para los avisos de Accesorios y teléfonos.';
        }

        return app(TelegramTemplateRenderer::class)->example($module, $code, $preview);
    }

    private function eligible(TelegramDestination $dest): bool
    {
        return $dest->responsible && $dest->responsible->is_active && $dest->responsible->hasAnyRole(['administrador', 'encargado']);
    }
}
