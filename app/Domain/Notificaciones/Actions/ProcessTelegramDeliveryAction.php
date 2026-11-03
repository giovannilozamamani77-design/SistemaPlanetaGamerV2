<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramDeliveryAttempt;
use App\Domain\Notificaciones\Services\TelegramDeliveryGuard;
use App\Domain\Notificaciones\Services\TelegramDeliveryPublisher;
use App\Infrastructure\Telegram\TelegramMessageClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessTelegramDeliveryAction
{
    public function execute(int $id, ?string $claimToken = null): void
    {
        $token = $claimToken ?? (string) Str::uuid();
        $d = DB::transaction(function () use ($id, $token) {
            $d = TelegramDelivery::whereKey($id)->lockForUpdate()->first();
            if (! $d || ! in_array($d->status, ['PENDIENTE', 'REINTENTO_PENDIENTE'], true) || $d->next_attempt_at?->isFuture()) {
                return null;
            }
            if ($code = app(TelegramDeliveryGuard::class)->deliveryReason($d)) {
                $d->update(['status' => $code === 'PAUSED' ? 'OMITIDA' : 'CANCELADA', 'last_code' => $code, 'next_attempt_at' => null]);

                return null;
            }
            $manual = $d->manual_pending;
            if (! $manual && $d->automatic_attempts >= 3) {
                $d->update(['status' => 'ERROR_DEFINITIVO', 'next_attempt_at' => null]);

                return null;
            }
            $d->fill(['status' => 'PROCESANDO', 'claim_token' => $token, 'lease_until' => now()->addSeconds(90), 'queued_at' => null, 'attempt_count' => $d->attempt_count + 1, 'automatic_attempts' => $d->automatic_attempts + ($manual ? 0 : 1), 'manual_pending' => false])->save();
            TelegramDeliveryAttempt::create(['delivery_id' => $d->id, 'number' => $d->attempt_count, 'kind' => $manual ? 'MANUAL' : 'AUTO', 'status' => 'PROCESANDO', 'started_at' => now()]);

            return $d;
        }, 3);
        if (! $d) {
            return;
        }
        try {
            if ($code = app(TelegramDeliveryGuard::class)->deliveryReason($d->fresh())) {
                $result = ['outcome' => 'CANCELLED', 'code' => $code];
            } else {
                $bot = TelegramBotSetting::findOrFail($d->bot_id);
                $result = app(TelegramMessageClient::class)->send($bot->token, $d->chat_id, $d->dispatch->message, $d->dispatch->media);
            }
        } catch (\Throwable $e) {
            $result = ['outcome' => 'UNCERTAIN', 'code' => 'PROCESS_UNCERTAIN'];
        }
        DB::transaction(function () use ($id, $token, $result) {
            $d = TelegramDelivery::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($d->status !== 'PROCESANDO' || $d->claim_token !== $token) {
                return;
            }
            $attempt = TelegramDeliveryAttempt::where('delivery_id', $id)->where('number', $d->attempt_count)->firstOrFail();
            $retry = $result['outcome'] === 'RETRYABLE' && $attempt->kind === 'AUTO' && $d->automatic_attempts < 3;
            $status = match ($result['outcome']) {
                'SENT' => 'ENVIADA','UNCERTAIN' => 'INCIERTA','PAUSED' => 'OMITIDA','CANCELLED' => 'CANCELADA',default => $retry ? 'REINTENTO_PENDIENTE' : 'ERROR_DEFINITIVO'
            };
            $d->update(['status' => $status, 'last_code' => $result['code'], 'message_id' => $result['message_id'] ?? null, 'lease_until' => null, 'next_attempt_at' => $retry ? now()->addSeconds(max(60, $result['retry_after'] ?? 60)) : null]);
            $attempt->update(['status' => $status, 'code' => $result['code'], 'http_status' => $result['http_status'] ?? null, 'finished_at' => now()]);
            if ($retry) {
                DB::afterCommit(fn () => app(TelegramDeliveryPublisher::class)->publish($id));
            }
        }, 3);
    }

    public function markUncertain(int $id, ?string $token, string $code = 'WORKER_INTERRUPTED'): void
    {
        DB::transaction(function () use ($id, $token, $code) {
            $d = TelegramDelivery::whereKey($id)->lockForUpdate()->first();
            if (! $d || $d->status !== 'PROCESANDO' || $d->claim_token !== $token) {
                return;
            }
            $d->update(['status' => 'INCIERTA', 'last_code' => $code, 'lease_until' => null, 'next_attempt_at' => null]);
            $attempt = TelegramDeliveryAttempt::where('delivery_id', $id)->where('number', $d->attempt_count)->where('status', 'PROCESANDO')->first();
            $attempt?->update(['status' => 'INCIERTA', 'code' => $code, 'finished_at' => now()]);
        }, 3);
    }
}
