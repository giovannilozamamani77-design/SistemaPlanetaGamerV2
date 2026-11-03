<?php

namespace App\Infrastructure\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class TelegramReadClient
{
    public function read(string $token, string $method, array $parameters = []): array
    {
        if (! in_array($method, ['getMe', 'getChat', 'getChatMember'], true)) {
            throw new \InvalidArgumentException('Método no permitido.');
        }
        try {
            $r = Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false] + app(TelegramTlsConfiguration::class)->options())->get('https://api.telegram.org/bot'.$token.'/'.$method, $parameters);
            if (! $r->successful()) {
                return ['ok' => false, 'code' => match ($r->status()) {
                    401,404 => 'TOKEN_INVALID',403 => 'ACCESS_DENIED',429 => 'RATE_LIMIT',default => 'API_UNAVAILABLE'
                }];
            }
            $body = $r->json();
            if (! is_array($body) || ($body['ok'] ?? null) !== true || ! is_array($body['result'] ?? null)) {
                return ['ok' => false, 'code' => 'INVALID_RESPONSE'];
            }

            return ['ok' => true, 'result' => $body['result']];
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'code' => 'CA_BUNDLE_UNAVAILABLE'];
        } catch (ConnectionException|RequestException $e) {
            // Never propagate an exception whose URL contains the bot credential.
            return ['ok' => false, 'code' => app(TelegramTlsConfiguration::class)->connectionCode($e)];
        }
    }
}
