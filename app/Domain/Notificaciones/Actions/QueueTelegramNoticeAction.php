<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Data\TelegramEventCatalog;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Services\TelegramDeliveryGuard;
use App\Domain\Notificaciones\Services\TelegramDeliveryPublisher;
use App\Domain\Notificaciones\Services\TelegramTemplateRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QueueTelegramNoticeAction
{
    public function execute(string $module, string $eventCode, string $sourceReference, string $message, ?array $media = null): TelegramDispatch
    {
        app(TelegramEventCatalog::class)->event($module, $eventCode);
        Validator::make(['reference' => $sourceReference, 'message' => $message], ['reference' => 'required|string|max:160|regex:/\A[A-Za-z0-9_:#.\-]+\z/', 'message' => 'required|string|max:4096'])->validate();
        $key = hash('sha256', json_encode([$module, $eventCode, $sourceReference]));
        $hash = hash_hmac('sha256', $media === null ? $message : json_encode([$message, $media], JSON_THROW_ON_ERROR), app('encrypter')->getKey());

        return DB::transaction(function () use ($module, $eventCode, $sourceReference, $message, $media, $key, $hash) {
            $bot = TelegramBotSetting::where('module', $module)->lockForUpdate()->first();
            if ($old = TelegramDispatch::where('event_key', $key)->first()) {
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['message' => 'El hecho ya fue registrado con otro contenido.']);
                }

                return $old;
            }
            $event = $bot ? TelegramEventSetting::with('destinations')->where('bot_id', $bot->id)->where('event_code', $eventCode)->first() : null;
            $eligible = collect();
            if (config('telegram.sending_enabled', false) && $event) {
                $eligible = $event->destinations->filter(fn ($d) => app(TelegramDeliveryGuard::class)->reason($event, $d) === null);
            }
            $ready = $eligible->isNotEmpty();
            $presentation = $ready ? app(TelegramTemplateRenderer::class)->apply($module, $eventCode, $message, $media) : ['message' => null, 'media' => null, 'revision' => 0, 'applied' => false];
            $dispatch = TelegramDispatch::create(['event_key' => $key, 'module' => $module, 'event_code' => $eventCode, 'source_reference' => $sourceReference, 'message' => $presentation['message'], 'media' => $presentation['media'], 'payload_hash' => $hash, 'status' => $ready ? 'EN_COLA' : 'OMITIDA', 'reason' => $ready ? null : (! config('telegram.sending_enabled', false) || ! $bot?->is_enabled ? 'PAUSED' : 'NO_AUTHORIZED_DESTINATION')]);
            foreach ($eligible as $dest) {
                $fingerprint = hash_hmac('sha256', (string) $dest->chat_id, app('encrypter')->getKey());
                $delivery = TelegramDelivery::firstOrCreate(['dispatch_id' => $dispatch->id, 'chat_fingerprint' => $fingerprint], ['bot_id' => $bot->id, 'destination_id' => $dest->id, 'event_setting_id' => $event->id, 'destination_name' => $dest->name, 'chat_id' => $dest->chat_id, 'snapshot' => ['bot_name' => $bot->name, 'bot_state_version' => (int) TelegramConfigurationChange::where('kind', 'BOT_STATE')->where('source_id', $bot->id)->max('id'), 'bot_revision' => (int) $bot->revision, 'destination_revision' => (int) $dest->revision, 'event_revision' => (int) $event->revision, 'responsible_id' => (int) $dest->responsible_id, 'template_version' => $presentation['revision'] > 0 ? 3 : (int) ($media['version'] ?? $event->template_version), 'presentation_revision' => $presentation['revision'], 'presentation_applied' => $presentation['applied']], 'status' => 'PENDIENTE', 'next_attempt_at' => now()]);
                DB::afterCommit(fn () => app(TelegramDeliveryPublisher::class)->publish($delivery->id));
            }

            return $dispatch;
        }, 3);
    }
}
