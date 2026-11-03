<?php

namespace App\Domain\Notificaciones\Services;

class AccessoryTelegramPhoto
{
    public function __construct(private TelegramPhotoSnapshot $photos) {}

    public function snapshot(string $message): ?array
    {
        return $this->photos->fromLogo('logoReciboPrestamo.png', $this->caption($message));
    }

    public function caption(string $message): string
    {
        if (mb_strlen($message, 'UTF-8') <= 1024) {
            return $message;
        }
        $main = [];
        $details = [];
        $omitted = false;
        foreach (explode("\n", $message) as $line) {
            if (str_starts_with($line, '… y ')) {
                $omitted = true;

                continue;
            }
            if (str_starts_with($line, 'Teléfono · ') || str_starts_with($line, 'Accesorio · ') || str_starts_with($line, 'Ver ')) {
                $details[] = $line;

                continue;
            }
            if (preg_match('/^(Responsable:|Beneficiaria:) /u', $line)) {
                [$label, $name] = explode(': ', $line, 2);
                $line = $label.': '.mb_strimwidth($name, 0, 48, '…', 'UTF-8');
            }
            $main[] = $line;
        }
        $caption = implode("\n", $main);
        foreach ($details as $line) {
            if (mb_strlen($caption."\n".$line, 'UTF-8') <= 970) {
                $caption .= "\n".$line;
            } else {
                $omitted = true;
            }
        }
        if ($omitted) {
            $caption .= "\nDetalle completo en el historial del sistema.";
        }

        return $caption;
    }
}
