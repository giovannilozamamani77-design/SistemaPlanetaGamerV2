<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveTelegramDestinationAction
{
    public function execute(User $actor, array $data): TelegramDestination
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        $v = Validator::make($data, [
            'id' => ['nullable', 'integer', 'min:1'], 'bot_id' => ['required', 'integer', 'min:1'],
            'responsible_id' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(['PRIVATE', 'GROUP'])], 'chat_id' => ['required', 'string', 'regex:/\A-?[1-9][0-9]{0,19}\z/'],
            'private_confirmed' => ['required', 'accepted'], 'expected_revision' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid'],
            'is_enabled' => ['prohibited'], 'enabled' => ['prohibited'],
        ])->validate();
        $v['name'] = trim($v['name']);
        if ($v['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Indica un nombre para reconocer el destino.']);
        }
        if (($v['kind'] === 'PRIVATE' && str_starts_with($v['chat_id'], '-')) || ($v['kind'] === 'GROUP' && ! str_starts_with($v['chat_id'], '-'))) {
            throw ValidationException::withMessages(['chat_id' => 'El Chat ID no corresponde al tipo elegido.']);
        }
        $hash = hash_hmac('sha256', json_encode($v, JSON_THROW_ON_ERROR), app('encrypter')->getKey());

        return DB::transaction(function () use ($actor, $v, $hash): TelegramDestination {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(TelegramSettingsQuery::class)->authorize($actor);
            if ($old = TelegramConfigurationChange::where('request_key', $v['request_key'])->first()) {
                if ((int) $old->actor_id !== $u->id) {
                    throw new AuthorizationException;
                }
                if ($old->kind !== 'DESTINATION' || ! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['name' => 'Este envío ya se guardó con otros datos. Abre nuevamente el destino.']);
                }

                return TelegramDestination::findOrFail($old->source_id);
            }
            TelegramBotSetting::whereKey($v['bot_id'])->lockForUpdate()->firstOrFail();
            $recipient = User::whereKey($v['responsible_id'])->lockForUpdate()->first();
            if (! $recipient || ! $recipient->is_active || ! $recipient->hasAnyRole(['administrador', 'encargado'])) {
                throw ValidationException::withMessages(['responsible_id' => 'Selecciona una persona activa de Administración o Encargado.']);
            }
            $destination = isset($v['id']) ? TelegramDestination::whereKey($v['id'])->lockForUpdate()->firstOrFail() : null;
            if ((int) ($destination?->revision ?? 0) !== (int) $v['expected_revision']) {
                throw ValidationException::withMessages(['name' => 'El destino cambió. Cierra y vuelve a abrir el formulario.']);
            }
            if (TelegramDestination::where('bot_id', $v['bot_id'])->where('chat_id', $v['chat_id'])->when($destination, fn ($q) => $q->where('id', '!=', $destination->id))->exists()) {
                throw ValidationException::withMessages(['chat_id' => 'Este bot ya tiene registrado ese destino.']);
            }
            $before = $destination?->only(['name', 'bot_id', 'responsible_id', 'chat_id', 'kind', 'revision', 'is_enabled']);
            $destination ??= new TelegramDestination;
            $destination->fill(collect($v)->only(['name', 'bot_id', 'responsible_id', 'chat_id', 'kind'])->all() + ['revision' => (int) $v['expected_revision'] + 1, 'updated_by' => $u->id, 'is_enabled' => false]);
            $destination->save();
            TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'DESTINATION', 'source_id' => $destination->id, 'snapshot' => ['before' => $before, 'after' => $destination->only(['name', 'bot_id', 'responsible_id', 'chat_id', 'kind', 'revision', 'is_enabled']), 'private_declared' => true], 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);

            return $destination;
        }, 3);
    }
}
