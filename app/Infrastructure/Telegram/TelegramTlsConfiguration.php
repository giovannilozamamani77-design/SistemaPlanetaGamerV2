<?php

namespace App\Infrastructure\Telegram;

class TelegramTlsConfiguration
{
    public function options(): array
    {
        $bundle = config('telegram.ca_bundle');
        if ($bundle === null || $bundle === '') {
            return ['verify' => true];
        }
        if (! is_string($bundle) || ! is_file($bundle) || ! is_readable($bundle)) {
            throw new \InvalidArgumentException('Telegram CA bundle is unavailable.');
        }

        return ['verify' => $bundle];
    }

    public function connectionCode(\Throwable $error): string
    {
        for ($depth = 0; $error && $depth < 5; $depth++, $error = $error->getPrevious()) {
            $context = method_exists($error, 'getHandlerContext') ? $error->getHandlerContext() : [];
            if (in_array($context['errno'] ?? 0, [60, 77, 83], true) || preg_match('/cURL error (60|77|83)(?:\D|$)/', $error->getMessage())) {
                return 'SSL_CERTIFICATE_FAILED';
            }
        }

        return 'CONNECTION_FAILED';
    }
}
