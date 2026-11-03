<?php

namespace App\Domain\Notificaciones\Services;

use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramEventSetting;

class TelegramDeliveryGuard
{
    public function reason(TelegramEventSetting $event, TelegramDestination $dest, bool $forActivation = false): ?string
    {
        if (! config('telegram.sending_enabled', false)) {
            return 'PAUSED';
        }
        $bot = TelegramBotSetting::find($event->bot_id);
        if ($bot && ! $forActivation && (! $bot->is_enabled || ! $dest->is_enabled)) {
            return 'PAUSED';
        }
        if (! $bot || ! $event->prepared) {
            return 'DISABLED';
        }
        if ((int) $dest->bot_id !== $bot->id || (int) $event->bot_revision !== (int) $bot->revision) {
            return 'CONFIG_CHANGED';
        }
        $pivot = $event->destinations()->where('telegram_destinations.id', $dest->id)->first();
        if (! $pivot || (int) $pivot->pivot->destination_revision !== (int) $dest->revision) {
            return 'CONFIG_CHANGED';
        }

        return $this->connectionReason($bot, $dest);
    }

    /** Shared access checks; a manual test does not require an active bot or event. */
    public function connectionReason(TelegramBotSetting $bot, TelegramDestination $dest): ?string
    {
        if (! config('telegram.sending_enabled', false)) {
            return 'PAUSED';
        }
        if ((int) $dest->bot_id !== (int) $bot->id) {
            return 'CONFIG_CHANGED';
        }
        $owner = $dest->responsible;
        if (! $owner || ! $owner->is_active || ! $owner->hasAnyRole(['administrador', 'encargado'])) {
            return 'RECIPIENT_UNAUTHORIZED';
        }
        foreach (['BOT_CHECK' => $bot->id, 'DESTINATION_CHECK' => $dest->id] as $kind => $id) {
            $check = TelegramConfigurationChange::where('kind', $kind)->where('source_id', $id)->latest('id')->first();
            $rev = $kind === 'BOT_CHECK' ? $bot->revision : $dest->revision;
            if (! $check || ($check->snapshot['status'] ?? '') !== 'CONNECTED' || (int) ($check->snapshot['after']['revision'] ?? 0) !== (int) $rev || (int) ($check->snapshot['bot_revision'] ?? 0) !== (int) $bot->revision) {
                return 'CONNECTION_UNVERIFIED';
            }
        }

        return null;
    }

    public function deliveryReason(TelegramDelivery $delivery): ?string
    {
        if (! config('telegram.sending_enabled', false)) {
            return 'PAUSED';
        }
        $bot = TelegramBotSetting::find($delivery->bot_id);
        if ($bot && ! $bot->is_enabled) {
            return 'PAUSED';
        }
        $version = (int) TelegramConfigurationChange::where('kind', 'BOT_STATE')->where('source_id', $delivery->bot_id)->max('id');
        if ($version !== (int) ($delivery->snapshot['bot_state_version'] ?? 0)) {
            return 'CONFIG_CHANGED';
        }
        $event = TelegramEventSetting::find($delivery->event_setting_id);
        $dest = TelegramDestination::find($delivery->destination_id);
        $s = $delivery->snapshot;
        if (! $event || ! $dest || (int) $delivery->bot_id !== (int) $event->bot_id || (int) $event->revision !== ($s['event_revision'] ?? 0) || (int) $dest->revision !== ($s['destination_revision'] ?? 0) || (int) $dest->responsible_id !== ($s['responsible_id'] ?? 0) || (int) $event->bot_revision !== ($s['bot_revision'] ?? 0)) {
            return 'CONFIG_CHANGED';
        }

        return $this->reason($event, $dest);
    }
}
