<?php

namespace App\Domain\Notificaciones\Services;

use App\Domain\Cajas\Models\CashBox;

class CashBoxTelegramPhoto
{
    public function __construct(private TelegramPhotoSnapshot $photos) {}

    public function snapshot(CashBox $box, string $caption): ?array
    {
        $family = in_array($box->type, ['BCP', 'TIGO_MONEY'], true) ? $box->type : ($box->exists ? $box->family() : $box->type);
        $filename = match ($family) {
            'BCP' => 'agente bcp cajas.jpg',
            'TIGO_MONEY' => 'tigo money caja principa PTM.png',
            default => null,
        };
        if ($filename === null) {
            return null;
        }

        return $this->photos->fromLogo($filename, $caption);
    }

    public function caption(string $message): string
    {
        $lines = explode("\n", $message);
        $heading = array_shift($lines);
        $main = [];
        $balances = [];
        foreach ($lines as $line) {
            if (str_contains($line, ' · esperado ')) {
                $balances[] = '• '.preg_replace('/ · esperado .*? · contado /u', ': ', $line);
            } elseif (preg_match('/^(Caja:|Responsable:|Turno:|Sesión #|Cierre #|Total contado:|Esperado:|Contado:|Faltante registrado:|Sobrante registrado:|Capital operativo|Saldo posterior:|Liquidación|Estado:|Responsable anterior:|✅ Sin diferencias)/u', $line)) {
                $main[] = mb_strimwidth($line, 0, 110, '…', 'UTF-8');
            }
        }
        $caption = $heading."\n\n".implode("\n", $main);
        $caption = str_replace(['Caja: ', 'Responsable: ', 'Sesión #', 'Cierre #', 'Total contado: ', 'Capital operativo de referencia: '], ['🏦 Caja: ', '👤 Responsable: ', '📅 Sesión #', '📅 Cierre #', '💰 Total contado: ', 'Capital operativo: '], $caption);
        $budget = 1024 - mb_strlen($caption, 'UTF-8') - 90;
        $included = [];
        foreach ($balances as $line) {
            if (mb_strlen(implode("\n", [...$included, $line]), 'UTF-8') <= $budget) {
                $included[] = $line;
            }
        }
        if ($included) {
            $caption .= "\n\nSALDOS CONTADOS\n".implode("\n", $included);
        }
        if (count($included) < count($balances)) {
            $caption .= "\nDetalle completo en el historial del sistema.";
        }

        return $caption;
    }
}
