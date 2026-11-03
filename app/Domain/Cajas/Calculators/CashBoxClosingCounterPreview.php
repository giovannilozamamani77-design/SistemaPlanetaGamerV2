<?php

namespace App\Domain\Cajas\Calculators;

class CashBoxClosingCounterPreview
{
    public function calculate(array $readings, array $references): array
    {
        $counters = [];
        $valid = true;
        foreach (['general', 'fixed'] as $key) {
            $raw = $readings[$key] ?? '';
            $reference = $references['counters'][$key]['value'];
            $error = null;
            $current = null;
            if ((! is_string($raw) && ! is_int($raw)) || ! preg_match('/\A[0-9]{1,9}\z/', (string) $raw)) {
                $error = 'Ingresa el contador acumulado como número entero, de cero a 999999999.';
            } else {
                $current = (int) $raw;
                if ($reference !== null && $current < $reference) {
                    $error = 'El contador no puede ser menor que la última lectura válida de esta caja y mes.';
                }
            }
            $valid = $valid && $error === null;
            $counters[$key] = ['current' => $current, 'reference' => $reference,
                'delta' => $error === null && $reference !== null ? $current - $reference : null, 'error' => $error];
        }

        return ['valid' => $valid, 'counters' => $counters];
    }
}
