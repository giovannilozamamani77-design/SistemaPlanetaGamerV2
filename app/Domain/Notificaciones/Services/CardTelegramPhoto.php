<?php

namespace App\Domain\Notificaciones\Services;

class CardTelegramPhoto
{
    public function __construct(private TelegramPhotoSnapshot $photos) {}

    public function snapshot(string $message, ?string $company = null): ?array
    {
        $filename = match (strtoupper(trim($company ?? ''))) {
            'ENTEL' => 'entel.jpg',
            'TIGO' => 'recaraga o cuentas tigo money.png',
            'VIVA' => 'recarga viva.jpg',
            default => 'logoReciboPrestamo.png',
        };

        return $this->photos->fromLogo($filename, $this->caption($message));
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
            if (str_contains($line, ' · esperado ') || str_contains($line, ' · pagado ') || str_starts_with($line, 'Ver ')) {
                $details[] = $line;

                continue;
            }
            if (preg_match('/^(Responsable:|Responsable anterior\/inicial:|Responsable del turno:) /u', $line)) {
                [$label, $name] = explode(': ', $line, 2);
                $line = $label.': '.mb_strimwidth($name, 0, 48, '…', 'UTF-8');
            }
            $line = str_replace([
                'Deuda automática registrada; no requiere confirmar el faltante.',
                'Revisar reservas: sobrantes retenidos según su registro. No compensa faltantes ni aumenta el disponible.',
            ], ['Deuda automática; no requiere aprobación.', '⚠ Reservas retenidas: no compensan faltantes ni son disponibles.'], $line);
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
