<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use App\Domain\Notificaciones\Services\TelegramDeliveryGuard;
use App\Domain\Notificaciones\Services\TelegramDeliveryPublisher;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RetryTelegramDeliveryAction
{
    public function execute(User $actor, array $data): TelegramDelivery
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        $v = Validator::make($data, ['delivery_id' => 'required|integer|min:1', 'expected_attempt_count' => 'required|integer|min:0', 'reason' => 'required|string|max:300', 'confirm_uncertain' => 'required|boolean', 'request_key' => 'required|uuid'])->validate();
        $v['reason'] = trim($v['reason']);
        if ($v['reason'] === '') {
            throw ValidationException::withMessages(['reason' => 'Indica por qué solicitas el reintento.']);
        }
        $hash = hash_hmac('sha256', json_encode($v, JSON_THROW_ON_ERROR), app('encrypter')->getKey());

        return DB::transaction(function () use ($actor, $v, $hash) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(TelegramSettingsQuery::class)->authorize($actor);
            if (! config('telegram.sending_enabled', false)) {
                throw ValidationException::withMessages(['reason' => 'Los envíos siguen pausados. No se encolará un reintento.']);
            }
            if ($old = TelegramConfigurationChange::where('request_key', $v['request_key'])->first()) {
                if ((int) $old->actor_id !== $u->id) {
                    throw new AuthorizationException;
                }
                if ($old->kind !== 'DELIVERY_RETRY' || ! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['reason' => 'Solicitud ya utilizada con otros datos.']);
                }

                return TelegramDelivery::findOrFail($old->source_id);
            }
            $d = TelegramDelivery::whereKey($v['delivery_id'])->lockForUpdate()->firstOrFail();
            if (! in_array($d->status, ['ERROR_DEFINITIVO', 'INCIERTA'], true) || $d->attempt_count !== (int) $v['expected_attempt_count']) {
                throw ValidationException::withMessages(['reason' => 'La entrega cambió o ya no permite reintento. Abre nuevamente el detalle.']);
            }
            if ($d->status === 'INCIERTA' && ! $v['confirm_uncertain']) {
                throw ValidationException::withMessages(['confirm_uncertain' => 'Confirma que revisaste el destino y aceptas el posible duplicado antes de repetir.']);
            }
            if (app(TelegramDeliveryGuard::class)->deliveryReason($d) !== null) {
                throw ValidationException::withMessages(['reason' => 'La configuración o autorización cambió. No se redirige ni reactiva esta entrega automáticamente.']);
            }
            $before = $d->only(['status', 'attempt_count', 'automatic_attempts']);
            $d->update(['status' => 'PENDIENTE', 'manual_pending' => true, 'next_attempt_at' => now(), 'queued_at' => null]);
            TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'DELIVERY_RETRY', 'source_id' => $d->id, 'snapshot' => ['before' => $before, 'after' => $d->only(['status', 'attempt_count', 'automatic_attempts']), 'reason' => $v['reason'], 'uncertainty_acknowledged' => (bool) $v['confirm_uncertain']], 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            DB::afterCommit(fn () => app(TelegramDeliveryPublisher::class)->publish($d->id));

            return $d;
        }, 3);
    }
}
