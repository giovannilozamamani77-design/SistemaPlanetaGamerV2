<?php

namespace App\Domain\Notificaciones\Services;

class LoanTelegramPhoto
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
        $optional = [];
        foreach (explode("\n", $message) as $line) {
            if (preg_match('/^(Detalle:|Ver préstamo|Operación #|Fecha de pago indicada)/u', $line)) {
                $optional[] = $line;

                continue;
            }
            if (preg_match('/^(Cliente:|Objeto:|Responsable:) /u', $line)) {
                [$label, $value] = explode(': ', $line, 2);
                $line = $label.': '.mb_strimwidth($value, 0, 48, '…', 'UTF-8');
            }
            $main[] = $line;
        }
        $caption = implode("\n", $main);
        $omitted = false;
        foreach ($optional as $line) {
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
