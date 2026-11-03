<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Data\TelegramEventCatalog;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Queries\TelegramEventsQuery;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use App\Domain\Notificaciones\Services\TelegramDeliveryGuard;
use App\Domain\Notificaciones\Services\TelegramTemplateRenderer;
use App\Infrastructure\Telegram\TelegramMessageClient;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendTelegramTestMessageAction
{
    public function execute(User $actor, array $input): array
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        $v = Validator::make($input, [
            'event_code' => 'sometimes|nullable|string|max:60', 'expected_template_revision' => 'required_with:event_code|integer|min:0',
            'bot_id' => 'required|integer|min:1', 'destination_id' => 'required|integer|min:1',
            'expected_revision' => 'required|integer|min:1', 'destination_revisions' => 'required|array',
            'destination_revisions.*' => 'integer|min:1', 'confirmed' => 'required|accepted', 'request_key' => 'required|uuid',
        ])->validate();
        if (DB::transactionLevel() > 0) {
            throw ValidationException::withMessages(['destination_id' => 'Envía la prueba fuera de una operación de caja o transacción pendiente.']);
        }
        $hash = hash_hmac('sha256', json_encode($v, JSON_THROW_ON_ERROR), app('encrypter')->getKey());
        [$request, $created] = DB::transaction(function () use ($actor, $v, $hash): array {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(TelegramSettingsQuery::class)->authorize($actor);
            $bot = TelegramBotSetting::whereKey($v['bot_id'])->lockForUpdate()->firstOrFail();
            if ($old = TelegramConfigurationChange::where('request_key', $v['request_key'])->first()) {
                if ((int) $old->actor_id !== $u->id) {
                    throw new AuthorizationException;
                }
                if ($old->kind !== 'BOT_TEST_REQUEST' || ! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['destination_id' => 'Esta solicitud ya se utilizó con otros datos. Abre nuevamente la prueba.']);
                }

                return [$old, false];
            }
            [, $dest] = $this->configuration($v);
            $recent = TelegramConfigurationChange::where('kind', 'BOT_TEST_REQUEST')->where('created_at', '>', now()->subMinute());
            if ((clone $recent)->where('source_id', $bot->id)->exists() || (clone $recent)->where('actor_id', $u->id)->count() >= 5) {
                throw ValidationException::withMessages(['destination_id' => 'Espera un minuto antes de enviar otra prueba de este bot (máximo cinco por administrador).']);
            }
            $example = $this->example($u, $bot, $v);
            $request = TelegramConfigurationChange::create([
                'actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'BOT_TEST_REQUEST', 'source_id' => $bot->id,
                'snapshot' => ['status' => 'PENDING', 'message' => $example['message'], 'message_template_version' => $example['template_version'], 'event_code' => $v['event_code'] ?? null, 'presentation_revision' => $example['presentation_revision'], 'encrypted_media' => $example['media'] ? Crypt::encryptString(json_encode($example['media'], JSON_THROW_ON_ERROR)) : null, 'module' => $bot->module, 'bot_name' => $bot->name, 'destination_name' => $dest->name, 'bot_id' => $bot->id, 'destination_id' => (int) $v['destination_id'], 'after' => ['revision' => $bot->revision]],
                'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now(),
            ]);

            return [$request, true];
        }, 3);
        if (! $created) {
            return $this->result($request);
        }
        // The committed request consumes its UUID before HTTP. A crash cannot resend it.
        try {
            app(TelegramSettingsQuery::class)->authorize($actor);
            [$bot, $dest] = $this->configuration($v);
            $token = $bot->token;
            TelegramConfigurationChange::create([
                'actor_id' => $request->actor_id, 'actor_name' => $request->actor_name, 'kind' => 'BOT_TEST_ATTEMPT', 'source_id' => $request->id,
                'snapshot' => ['status' => 'STARTED', 'bot_id' => $bot->id, 'destination_id' => $dest->id],
                'request_key' => (string) Str::uuid(), 'payload_hash' => $hash, 'created_at' => now(),
            ]);
            $result = app(TelegramMessageClient::class)->send($token, $dest->chat_id, $request->snapshot['message'], isset($request->snapshot['encrypted_media']) ? json_decode(Crypt::decryptString($request->snapshot['encrypted_media']), true, 512, JSON_THROW_ON_ERROR) : null);
        } catch (AuthorizationException|ValidationException $e) {
            $result = ['outcome' => 'CANCELLED', 'code' => 'CONFIG_CHANGED'];
        } catch (\Throwable $e) {
            $result = ['outcome' => 'UNCERTAIN', 'code' => 'TEST_UNCERTAIN'];
        }
        $snapshot = [
            'status' => match ($result['outcome']) {
                'SENT' => 'SENT', 'RETRYABLE', 'PERMANENT' => 'REJECTED', 'PAUSED' => 'PAUSED', 'CANCELLED' => 'CANCELLED', default => 'UNCERTAIN',
            },
            'bot_id' => (int) $v['bot_id'], 'destination_id' => (int) $v['destination_id'],
            'code' => $result['code'], 'message_id' => $result['message_id'] ?? null,
            'http_status' => $result['http_status'] ?? null,
        ];
        TelegramConfigurationChange::create([
            'actor_id' => $request->actor_id, 'actor_name' => $request->actor_name, 'kind' => 'BOT_TEST_RESULT', 'source_id' => $request->id,
            'snapshot' => $snapshot, 'request_key' => (string) Str::uuid(), 'payload_hash' => $hash, 'created_at' => now(),
        ]);

        return $snapshot;
    }

    private function configuration(array $v): array
    {
        $bot = TelegramBotSetting::findOrFail($v['bot_id']);
        $dest = TelegramDestination::find($v['destination_id']);
        if (! $dest || (int) $bot->revision !== (int) $v['expected_revision']
            || (int) $dest->revision !== (int) ($v['destination_revisions'][$dest->id] ?? 0)
            || app(TelegramDeliveryGuard::class)->connectionReason($bot, $dest) !== null) {
            throw ValidationException::withMessages(['destination_id' => 'Comprueba bot y destino, su responsable y sus revisiones antes de enviar. Elige un destino privado de este bot.']);
        }

        if (! empty($v['event_code'])) {
            app(TelegramEventCatalog::class)->event($bot->module, $v['event_code']);
            if (app(TelegramTemplateRenderer::class)->revision($bot->module, $v['event_code']) !== (int) $v['expected_template_revision']) {
                throw ValidationException::withMessages(['destination_id' => 'La plantilla cambió. Abre nuevamente la prueba para revisar su versión.']);
            }
        }

        return [$bot, $dest];
    }

    private function example(User $actor, TelegramBotSetting $bot, array $v): array
    {
        if (empty($v['event_code'])) {
            return ['message' => self::text($bot->module), 'media' => null, 'template_version' => 1, 'presentation_revision' => 0];
        }
        $preview = app(TelegramEventsQuery::class)->preview($actor, $bot->module, $v['event_code']);
        $old = "EJEMPLO · Datos ficticios · No se envía\n\n";
        $prefix = "PRUEBA DE NOTIFICACIÓN · DATOS FICTICIOS\nNo representa una operación real.\n\n";
        $preview['message'] = $prefix.(str_starts_with($preview['message'], $old) ? substr($preview['message'], strlen($old)) : $preview['message']);
        if ($preview['media'] ?? null) {
            $caption = $preview['media']['caption'];
            $preview['media']['caption'] = $prefix.(str_starts_with($caption, $old) ? substr($caption, strlen($old)) : $caption);
            if (mb_strlen($preview['media']['caption']) > 1024) {
                $preview['media'] = null;
            }
        }

        return $preview + ['media' => null, 'presentation_revision' => 0];
    }

    private function result(TelegramConfigurationChange $request): array
    {
        return TelegramConfigurationChange::where('kind', 'BOT_TEST_RESULT')->where('source_id', $request->id)->first()?->snapshot ?? $request->snapshot;
    }

    public static function text(string $module): string
    {
        return "PRUEBA DE CONEXIÓN · Planeta Gamer\nMódulo: ".TelegramSettingsQuery::MODULES[$module]."\nEste es un mensaje de prueba solicitado desde Configuraciones. No representa una operación real y no cambia el estado de los avisos automáticos.";
    }

    public static function message(array $result): string
    {
        return match ($result['status'] ?? '') {
            'SENT' => 'Prueba enviada · avisos automáticos sin cambios',
            'REJECTED' => 'Telegram rechazó la prueba. No se reintentará automáticamente.',
            'PAUSED' => 'Transporte pausado por mantenimiento. Prueba no enviada.',
            'CANCELLED' => 'La configuración cambió. Prueba cancelada.',
            'UNCERTAIN' => 'Resultado sin confirmar. Revisa Telegram antes de enviar otra prueba.',
            default => 'Prueba en proceso o sin resultado confirmado. Revisa Telegram antes de repetir.',
        };
    }
}
