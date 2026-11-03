<?php

namespace App\Domain\Notificaciones\Services;

use App\Domain\Notificaciones\Actions\ProcessTelegramDeliveryAction;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Jobs\SendTelegramDelivery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class TelegramDeliveryPublisher
{
    public function publish(int $id): bool
    {
        if (! config('telegram.sending_enabled', false) || ! TelegramBotSetting::whereKey(TelegramDelivery::whereKey($id)->value('bot_id'))->where('is_enabled', true)->exists()) {
            TelegramDelivery::whereKey($id)->whereIn('status', ['PENDIENTE', 'REINTENTO_PENDIENTE'])->update(['status' => 'OMITIDA', 'last_code' => 'PAUSED', 'next_attempt_at' => null]);

            return false;
        }
        $claimed = TelegramDelivery::whereKey($id)->whereIn('status', ['PENDIENTE', 'REINTENTO_PENDIENTE'])->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<=', now()->subSeconds(120)))->update(['queued_at' => now()]);
        if (! $claimed) {
            return false;
        }
        try {
            $d = TelegramDelivery::findOrFail($id);
            $delay = max(0, ($d->next_attempt_at?->getTimestamp() ?? now()->getTimestamp()) - now()->getTimestamp());
            Queue::connection('telegram')->laterOn('telegram', $delay, new SendTelegramDelivery($id));

            return true;
        } catch (\Throwable $e) {
            TelegramDelivery::whereKey($id)->whereIn('status', ['PENDIENTE', 'REINTENTO_PENDIENTE'])->update(['queued_at' => null]);
            Log::warning('Telegram queue unavailable', ['delivery_id' => $id]);

            return false;
        }
    }

    public function due(int $limit = 100): int
    {
        $limit = max(1, min(100, $limit));
        foreach (TelegramDelivery::where('status', 'PROCESANDO')->where('lease_until', '<=', now())->orderBy('id')->limit($limit)->get() as $d) {
            app(ProcessTelegramDeliveryAction::class)->markUncertain($d->id, $d->claim_token, 'WORKER_INTERRUPTED');
        }
        $count = 0;
        foreach (TelegramDelivery::whereIn('status', ['PENDIENTE', 'REINTENTO_PENDIENTE'])->where('next_attempt_at', '<=', now())->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<=', now()->subSeconds(120)))->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $count += (int) $this->publish($id);
        }

        return $count;
    }
}
