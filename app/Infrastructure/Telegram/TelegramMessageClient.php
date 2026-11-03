<?php

namespace App\Infrastructure\Telegram;

use Illuminate\Support\Facades\Http;

class TelegramMessageClient
{
    public function send(string $token, string $chatId, string $message, ?array $media = null): array
    {
        if (! config('telegram.sending_enabled', false)) {
            return ['outcome' => 'PAUSED', 'code' => 'PAUSED'];
        }
        try {
            $request = Http::connectTimeout(3)->timeout($media === null ? 8 : 20)->withOptions(['allow_redirects' => false] + app(TelegramTlsConfiguration::class)->options());
            if ($media !== null) {
                $bytes = base64_decode($media['content'] ?? '', true);
                $info = $bytes === false ? false : @getimagesizefromstring($bytes);
                if (! $info || strlen($bytes) > 262144 || ! in_array($info['mime'], ['image/jpeg', 'image/png'], true)
                    || ! hash_equals(hash('sha256', $bytes), $media['sha256'] ?? '')
                    || ! is_string($media['caption'] ?? null) || mb_strlen($media['caption'], 'UTF-8') > 1024) {
                    return ['outcome' => 'PERMANENT', 'code' => 'INVALID_MEDIA'];
                }
                $r = $request->attach('photo', $bytes, $info['mime'] === 'image/png' ? 'caja.png' : 'caja.jpg', ['Content-Type' => $info['mime']])
                    ->post('https://api.telegram.org/bot'.$token.'/sendPhoto', ['chat_id' => $chatId, 'caption' => $media['caption'], 'protect_content' => 'true']);
            } else {
                $r = $request->post('https://api.telegram.org/bot'.$token.'/sendMessage', ['chat_id' => $chatId, 'text' => $message, 'protect_content' => true, 'link_preview_options' => ['is_disabled' => true]]);
            }
            $body = $r->json();
            if (is_array($body) && ($body['ok'] ?? null) === true && $r->successful() && is_int($body['result']['message_id'] ?? null) && $body['result']['message_id'] > 0 && (string) ($body['result']['chat']['id'] ?? '') === $chatId) {
                return ['outcome' => 'SENT', 'code' => 'SENT', 'message_id' => (string) $body['result']['message_id'], 'http_status' => $r->status()];
            }
            if (is_array($body) && ($body['ok'] ?? null) === false && is_int($body['error_code'] ?? null)) {
                $error = $body['error_code'];
                if ($error === 429 || $error >= 500) {
                    return ['outcome' => 'RETRYABLE', 'code' => $error === 429 ? 'RATE_LIMIT' : 'API_REJECTED', 'retry_after' => min(86400, max(60, (int) ($body['parameters']['retry_after'] ?? 60))), 'http_status' => $r->status()];
                }
                if (in_array($error, [400, 401, 403, 404, 409], true)) {
                    return ['outcome' => 'PERMANENT', 'code' => 'API_REJECTED', 'http_status' => $r->status()];
                }
            }

            return ['outcome' => 'UNCERTAIN', 'code' => 'UNCONFIRMED_RESPONSE', 'http_status' => $r->status()];
        } catch (\InvalidArgumentException $e) {
            return ['outcome' => 'PERMANENT', 'code' => 'CA_BUNDLE_UNAVAILABLE'];
        } catch (\Throwable $e) {
            return ['outcome' => 'UNCERTAIN', 'code' => 'TRANSPORT_UNCERTAIN'];
        }
    }
}
