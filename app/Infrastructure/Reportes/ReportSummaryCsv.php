<?php

namespace App\Infrastructure\Reportes;

use RuntimeException;

class ReportSummaryCsv
{
    public function render(array $report, array $document): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('No se pudo preparar la exportación CSV.');
        }
        try {
            if (fwrite($stream, "\xEF\xBB\xBF") !== 3) {
                throw new RuntimeException('No se pudo escribir la exportación CSV.');
            }
            $this->row($stream, ['PLANETA GAMER · Resumen de Reportes']);
            $this->row($stream, [$document['mode_label'].' consultado', $report['period']['label']]);
            $this->row($stream, ['Periodo anterior', $report['comparison']['available'] ? $report['period']['previous_label'] : 'Sin comparación disponible']);
            $this->row($stream, ['Generado por', $document['actor']]);
            $this->row($stream, ['Generado · Bolivia', $document['generated_at']]);
            $this->row($stream, ['Documento', $document['id']]);
            if (isset($report['monthly_adjustment'])) {
                $this->row($stream, ['Cierre ajustado v'.$report['monthly_adjustment']['sequence'], $report['monthly_adjustment']['actor'], $report['monthly_adjustment']['confirmed_at'].' Bolivia']);
                $this->row($stream, ['Motivo', $report['monthly_adjustment']['reason']]);
                $this->row($stream, ['Indicador', 'Original · Bs', 'Ajustes · Bs', 'Resultado · Bs']);
                foreach (['sales' => 'Ventas', 'collections' => 'Cobros'] as $metric => $label) {
                    $this->row($stream, [$label, $report['monthly_adjustment']['original_'.$metric.'_cents'], $report['monthly_adjustment'][$metric.'_adjustment_cents'], $report[$metric.'_cents']], [1, 2, 3]);
                }
                $this->row($stream, ['Versión conservada. Comparación con el mes anterior observado en el cierre original.', $report['monthly_adjustment']['pending_count'].' pendientes observados.']);
            }
            if (isset($report['monthly_close'])) {
                $this->row($stream, ['Cierre original #'.$report['monthly_close']['id'], $report['monthly_close']['month'], $report['monthly_close']['actor'], $report['monthly_close']['confirmed_at'].' Bolivia']);
                $this->row($stream, ['Pendientes al confirmar', $report['monthly_close']['pending_count']]);
            }
            $this->row($stream, [$report['period']['is_current'] ? 'Periodo en curso: se compara lo registrado con el periodo anterior completo.' : (isset($report['monthly_adjustment']) ? 'Resultado conservado de esta versión; los cambios posteriores no alteran este archivo.' : (isset($report['monthly_close']) ? 'Datos originales conservados al confirmar. No se recalculan con operaciones posteriores.' : 'Consulta de operaciones vigentes al generar. No constituye cierre mensual.'))]);
            $this->row($stream, []);
            $this->row($stream, ['Ventas y cobros']);
            $this->row($stream, ['Indicador', 'Consultado · Bs', 'Anterior · Bs', 'Diferencia · Bs']);
            foreach (['sales' => 'Ventas registradas', 'collections' => 'Cobros registrados'] as $metric => $label) {
                $comparison = $report['comparison'][$metric];
                $this->row($stream, [$label, $report[$metric.'_cents'], $comparison['previous_cents'] ?? 'No disponible', $comparison['difference_cents'] ?? 'Sin comparación disponible'], [1, 2, 3]);
            }
            $this->row($stream, ['Ventas ya forma parte de Cobros: no se suman. Cobros incluye capital recuperado de Préstamos; no representa ganancias.']);
            $this->row($stream, []);
            $this->row($stream, ['Desglose del periodo consultado']);
            $this->row($stream, ['Origen', 'Registros', 'Ventas · Bs', 'Cobros · Bs']);
            foreach ($report['rows'] as $row) {
                $this->row($stream, [$row['label'], $row['operations'], $row['sales_cents'], $row['collections_cents']], [2, 3]);
            }
            $this->row($stream, ['Las ventas mixtas se separan por tipo sin duplicar importes; Registros no equivale a tickets únicos.']);
            $this->row($stream, []);
            if (($report['balance_scope'] ?? null) === 'excluded_monthly') {
                $this->row($stream, ['Cierre mensual: no incluye saldos actuales como saldos históricos. Pendientes separados, sin reconocerlos como pérdidas.']);
            } else {
                $this->row($stream, ['Dinero disponible al generar']);
                $this->row($stream, ['Saldos actuales por fondo, no históricos ni conciliados con el banco. No se suman fondos ni saldos digitales Entel.']);
                $this->row($stream, ['Fondo / componente', 'Disponible · Bs', 'Detalle']);
                foreach ($report['balances'] as $balance) {
                    $this->row($stream, [$balance['label'], $balance['amount_cents'] ?? 'No disponible', $balance['note']], [1]);
                }
            }
            if (! rewind($stream)) {
                throw new RuntimeException('No se pudo leer la exportación CSV.');
            }
            $bytes = stream_get_contents($stream);
            if ($bytes === false) {
                throw new RuntimeException('No se pudo leer la exportación CSV.');
            }

            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function row($stream, array $values, array $moneyColumns = []): void
    {
        foreach ($values as $index => $value) {
            if (in_array($index, $moneyColumns, true) && is_int($value)) {
                $absolute = abs($value);
                $values[$index] = ($value < 0 ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
            } elseif (is_string($value) && preg_match('/^(?:[\p{Z}\x00-\x20]*[=+\-@]|[\t\r\n])/u', $value)) {
                $values[$index] = "'".$value;
            }
        }
        if (fputcsv($stream, array_pad($values, 4, ''), ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('No se pudo escribir la exportación CSV.');
        }
    }
}
