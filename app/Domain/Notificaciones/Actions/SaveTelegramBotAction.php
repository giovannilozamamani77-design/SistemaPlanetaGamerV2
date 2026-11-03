<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveTelegramBotAction
{
    public function execute(User $actor, array $data): TelegramBotSetting
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        $v = Validator::make($data, [
            'module' => ['required', Rule::in(array_keys(TelegramSettingsQuery::MODULES))],
            'name' => ['required', 'string', 'max:100'], 'token' => ['nullable', 'string', 'max:180', 'regex:/\A[0-9]{5,20}:[A-Za-z0-9_-]{20,150}\z/'],
            'expected_revision' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid'],
            'is_enabled' => ['prohibited'], 'enabled' => ['prohibited'],
        ])->validate();
        $v['name'] = trim($v['name']);
        if ($v['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Indica el nombre del bot.']);
        }
        $hash = hash_hmac('sha256', json_encode($v, JSON_THROW_ON_ERROR), app('encrypter')->getKey());

        return DB::transaction(function () use ($actor, $v, $hash): TelegramBotSetting {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(TelegramSettingsQuery::class)->authorize($actor);
            if ($old = TelegramConfigurationChange::where('request_key', $v['request_key'])->first()) {
                if ((int) $old->actor_id !== $u->id) {
                    throw new AuthorizationException;
                }
                if ($old->kind !== 'BOT' || ! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['name' => 'Este envío ya se guardó con otros datos. Abre nuevamente la configuración.']);
                }

                return TelegramBotSetting::findOrFail($old->source_id);
            }
            $bot = TelegramBotSetting::where('module', $v['module'])->lockForUpdate()->first();
            if ((int) ($bot?->revision ?? 0) !== (int) $v['expected_revision']) {
                throw ValidationException::withMessages(['name' => 'La configuración cambió. Cierra y vuelve a abrir el formulario.']);
            }
            if (! $bot && empty($v['token'])) {
                throw ValidationException::withMessages(['token' => 'Ingresa el token para registrar este bot.']);
            }
            $before = $bot?->only(['module', 'name', 'revision', 'is_enabled']);
            $bot ??= new TelegramBotSetting(['module' => $v['module']]);
            $bot->fill(['name' => $v['name'], 'updated_by' => $u->id, 'revision' => (int) $v['expected_revision'] + 1, 'is_enabled' => false]);
            if (! empty($v['token'])) {
                $fingerprint = hash_hmac('sha256', $v['token'], app('encrypter')->getKey());
                if (TelegramBotSetting::where('token_fingerprint', $fingerprint)->when($bot->exists, fn ($q) => $q->where('id', '!=', $bot->id))->exists()) {
                    throw ValidationException::withMessages(['token' => 'Ese bot ya está configurado para otro módulo. Usa un bot separado.']);
                }
                $bot->token = $v['token'];
                $bot->token_fingerprint = $fingerprint;
            }
            $bot->save();
            TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'BOT', 'source_id' => $bot->id, 'snapshot' => ['before' => $before, 'after' => $bot->only(['module', 'name', 'revision', 'is_enabled']), 'token_replaced' => ! empty($v['token'])], 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);

            return $bot;
        }, 3);
    }
}
