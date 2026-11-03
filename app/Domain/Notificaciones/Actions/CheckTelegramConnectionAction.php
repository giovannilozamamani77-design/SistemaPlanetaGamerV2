<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use App\Infrastructure\Telegram\TelegramReadClient;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CheckTelegramConnectionAction
{
    public function execute(User $actor, array $input): array
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        $v = Validator::make($input, ['id' => 'required|integer|min:1', 'kind' => 'required|in:BOT,DESTINATION', 'expected_revision' => 'required|integer|min:1', 'bot_revision' => 'required|integer|min:1', 'request_key' => 'required|uuid'])->validate();
        $hash = hash_hmac('sha256', json_encode($v), app('encrypter')->getKey());
        [$bot,$dest] = $this->configuration($v);
        if ($old = $this->replay($actor, $v, $hash)) {
            return $old;
        }
        $key = 'telegram-check:'.$actor->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['connection' => 'Espera un minuto antes de comprobar nuevamente.']);
        }
        RateLimiter::hit($key, 60);
        $result = $this->inspect($bot, $dest);

        return DB::transaction(function () use ($actor, $v, $hash, $result) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(TelegramSettingsQuery::class)->authorize($actor);
            [$bot,$dest] = $this->configuration($v, true);
            if ($old = $this->replay($actor, $v, $hash)) {
                return $old;
            }
            $snapshot = ['after' => ['revision' => (int) $v['expected_revision']], 'bot_revision' => (int) $bot->revision] + $result;
            TelegramConfigurationChange::create(['actor_id' => $actor->id, 'actor_name' => $actor->fresh()->name, 'kind' => $v['kind'].'_CHECK', 'source_id' => $v['id'], 'snapshot' => $snapshot, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);

            return $snapshot;
        }, 3);
    }

    private function configuration(array $v, bool $lock = false): array
    {
        $dest = $v['kind'] === 'DESTINATION' ? TelegramDestination::findOrFail($v['id']) : null;
        $bot = TelegramBotSetting::when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($dest?->bot_id ?? $v['id']);
        if ($dest) {
            User::whereKey($dest->responsible_id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
            $dest = TelegramDestination::when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($v['id']);
            $owner = $dest->responsible;
            if (! $owner->is_active || ! $owner->hasAnyRole(['administrador', 'encargado'])) {
                throw ValidationException::withMessages(['connection' => 'El responsable ya no está autorizado.']);
            }
        }
        if ((int) ($dest?->revision ?? $bot->revision) !== (int) $v['expected_revision'] || (int) $bot->revision !== (int) $v['bot_revision'] || ($dest && (int) $dest->bot_id !== $bot->id)) {
            throw ValidationException::withMessages(['connection' => 'La configuración cambió. Abre nuevamente la comprobación.']);
        }

        return [$bot, $dest];
    }

    private function replay(User $u, array $v, string $hash): ?array
    {
        $old = TelegramConfigurationChange::where('request_key', $v['request_key'])->first();
        if (! $old) {
            return null;
        }
        if ((int) $old->actor_id !== $u->id) {
            throw new AuthorizationException;
        }
        if ($old->kind !== $v['kind'].'_CHECK' || ! hash_equals($old->payload_hash, $hash)) {
            throw ValidationException::withMessages(['connection' => 'Solicitud reutilizada con otros datos.']);
        }

        return $old->snapshot;
    }

    private function inspect(TelegramBotSetting $bot, ?TelegramDestination $dest): array
    {
        $fail = fn ($code) => ['status' => 'FAILED', 'code' => $code];
        try {
            $token = $bot->token;
        } catch (DecryptException $e) {
            return $fail('CREDENTIAL_UNAVAILABLE');
        }
        $client = app(TelegramReadClient::class);
        $r = $client->read($token, 'getMe');
        if (! $r['ok']) {
            return $fail($r['code']);
        }
        $me = $r['result'];
        $id = (string) ($me['id'] ?? '');
        if (($me['is_bot'] ?? false) !== true || ! ctype_digit($id) || $id !== strtok($token, ':')) {
            return $fail('IDENTITY_MISMATCH');
        }
        foreach (TelegramBotSetting::where('id', '!=', $bot->id)->get() as $other) {
            try {
                if (strtok($other->token, ':') === $id) {
                    return $fail('SHARED_BOT');
                }
            } catch (DecryptException $e) {
                return $fail('CREDENTIAL_UNAVAILABLE');
            }
        }
        if ($dest) {
            $r = $client->read($token, 'getChat', ['chat_id' => $dest->chat_id]);
            if (! $r['ok']) {
                return $fail($r['code']);
            } $chat = $r['result'];
            if ((string) ($chat['id'] ?? '') !== $dest->chat_id) {
                return $fail('CHAT_MISMATCH');
            }
            if ($dest->kind === 'PRIVATE' && ($chat['type'] ?? '') !== 'private') {
                return $fail('CHAT_TYPE');
            }
            if ($dest->kind === 'GROUP') {
                if (! in_array($chat['type'] ?? '', ['group', 'supergroup'], true)) {
                    return $fail('CHAT_TYPE');
                }
                if (! empty($chat['username']) || ! empty($chat['active_usernames'])) {
                    return $fail('PUBLIC_GROUP');
                }
                $r = $client->read($token, 'getChatMember', ['chat_id' => $dest->chat_id, 'user_id' => $id]);
                if (! $r['ok']) {
                    return $fail($r['code']);
                } $m = $r['result'];
                $status = $m['status'] ?? '';
                if ((string) ($m['user']['id'] ?? '') !== $id || ! in_array($status, ['creator', 'administrator', 'member', 'restricted'], true)) {
                    return $fail('BOT_NOT_MEMBER');
                }
                if ($status === 'restricted' && (($m['is_member'] ?? false) !== true || ($m['can_send_messages'] ?? false) !== true)) {
                    return $fail('ACCESS_DENIED');
                }
                if ($status === 'member' && ($chat['permissions']['can_send_messages'] ?? true) === false) {
                    return $fail('ACCESS_DENIED');
                }
            }
        }

        return ['status' => 'CONNECTED', 'code' => 'ACCESS_CHECKED', 'bot_id' => $id];
    }

    public static function message(array $result): string
    {
        return ($result['status'] ?? '') === 'CONNECTED' ? 'Acceso comprobado · no cambia el estado de envío.' : match ($result['code'] ?? '') {
            'SSL_CERTIFICATE_FAILED' => 'El servidor no pudo validar el certificado HTTPS de Telegram. Revisa sus certificados de confianza.',
            'CA_BUNDLE_UNAVAILABLE' => 'El paquete de certificados de Telegram no existe o no se puede leer en el servidor.',
            'CONNECTION_FAILED' => 'El servidor no pudo conectarse a Telegram. Revisa su conexión a internet y certificados HTTPS.',
            'TOKEN_INVALID' => 'Token no válido. Revisa la credencial.', 'PUBLIC_GROUP' => 'El grupo tiene un nombre público; usa un grupo privado.', 'RATE_LIMIT' => 'Telegram pide esperar. Inténtalo más tarde.', 'CHAT_TYPE','CHAT_MISMATCH' => 'El destino no coincide con el ID o tipo configurado.', 'ACCESS_DENIED','BOT_NOT_MEMBER' => 'El bot no tiene acceso suficiente al destino.', default => 'No se pudo comprobar la conexión. Revisa la configuración o inténtalo más tarde.'
        };
    }
}
