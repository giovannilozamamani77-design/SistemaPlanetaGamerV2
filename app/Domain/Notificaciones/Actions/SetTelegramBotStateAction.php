<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDelivery;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use App\Domain\Notificaciones\Services\TelegramDeliveryGuard;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SetTelegramBotStateAction
{
    public function execute(User $actor, array $data): TelegramBotSetting
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        $v = Validator::make($data, [
            'bot_id' => ['required', 'integer', 'min:1'],
            'enabled' => ['required', 'boolean'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'expected_state_version' => ['required', 'integer', 'min:0'],
            'destination_ids' => ['present', 'array', 'max:100'],
            'destination_ids.*' => ['integer', 'min:1', 'distinct'],
            'destination_revisions' => ['present', 'array'],
            'destination_revisions.*' => ['integer', 'min:1'],
            'request_key' => ['required', 'uuid'],
        ])->validate();
        $v['enabled'] = (bool) $v['enabled'];
        $v['destination_ids'] = array_map('intval', $v['destination_ids']);
        sort($v['destination_ids']);
        $hash = hash_hmac('sha256', json_encode($v, JSON_THROW_ON_ERROR), app('encrypter')->getKey());

        return DB::transaction(function () use ($actor, $v, $hash): TelegramBotSetting {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(TelegramSettingsQuery::class)->authorize($actor);
            $bot = TelegramBotSetting::whereKey($v['bot_id'])->lockForUpdate()->firstOrFail();
            if ($old = TelegramConfigurationChange::where('request_key', $v['request_key'])->first()) {
                if ((int) $old->actor_id !== $u->id) {
                    throw new AuthorizationException;
                }
                if ($old->kind !== 'BOT_STATE' || ! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['enabled' => 'Esta solicitud ya se utilizó con otros datos. Abre nuevamente el control.']);
                }

                return $bot;
            }
            $version = (int) TelegramConfigurationChange::where('kind', 'BOT_STATE')->where('source_id', $bot->id)->max('id');
            if ((int) $bot->revision !== (int) $v['expected_revision'] || $version !== (int) $v['expected_state_version']) {
                throw ValidationException::withMessages(['enabled' => 'El bot cambió. Cierra y vuelve a abrir este control.']);
            }
            $destinations = $bot->destinations()->with('responsible.roles')->lockForUpdate()->get();
            $selected = $destinations->whereIn('id', $v['destination_ids']);
            if ($v['enabled']) {
                if (! config('telegram.sending_enabled', false)) {
                    throw ValidationException::withMessages(['enabled' => 'El transporte de Telegram está pausado por mantenimiento.']);
                }
                if ($selected->isEmpty() || $selected->count() !== count($v['destination_ids'])) {
                    throw ValidationException::withMessages(['destination_ids' => 'Selecciona destinos de este bot, comprobados y con eventos preparados.']);
                }
                $events = TelegramEventSetting::where('bot_id', $bot->id)->where('prepared', true)->get();
                foreach ($selected as $dest) {
                    $ready = (int) ($v['destination_revisions'][$dest->id] ?? 0) === (int) $dest->revision
                        && $events->contains(fn ($event) => app(TelegramDeliveryGuard::class)->reason($event, $dest, true) === null);
                    if (! $ready) {
                        throw ValidationException::withMessages(['destination_ids' => 'Revisa conexión del bot y destino, responsable y asignación de eventos antes de activar.']);
                    }
                }
            }
            $before = ['is_enabled' => $bot->is_enabled, 'destination_ids' => $destinations->where('is_enabled', true)->pluck('id')->all()];
            // Terminal omissions ensure pause/resume cannot send an old queued notice.
            TelegramDelivery::where('bot_id', $bot->id)->whereIn('status', ['PENDIENTE', 'REINTENTO_PENDIENTE'])
                ->when($v['enabled'], fn ($q) => $q->whereNotIn('destination_id', $v['destination_ids']))
                ->update(['status' => 'OMITIDA', 'last_code' => 'PAUSED', 'next_attempt_at' => null, 'queued_at' => null]);
            foreach ($destinations as $dest) {
                $dest->update(['is_enabled' => $v['enabled'] && in_array($dest->id, $v['destination_ids'], true), 'updated_by' => $u->id]);
            }
            // Operational state does not alter the credential revision or invalidate checks.
            $bot->update(['is_enabled' => $v['enabled'], 'updated_by' => $u->id]);
            TelegramConfigurationChange::create([
                'actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'BOT_STATE', 'source_id' => $bot->id,
                'snapshot' => ['before' => $before, 'after' => ['is_enabled' => $v['enabled'], 'destination_ids' => $v['enabled'] ? $v['destination_ids'] : [], 'revision' => $bot->revision]],
                'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now(),
            ]);

            return $bot;
        }, 3);
    }
}
