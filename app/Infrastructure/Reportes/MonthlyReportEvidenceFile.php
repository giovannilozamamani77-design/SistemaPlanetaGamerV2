<?php

namespace App\Infrastructure\Reportes;

use Carbon\CarbonImmutable;

class MonthlyReportEvidenceFile extends ReportRecordsFile
{
    protected function rows(array $context, array $document, iterable $records): \Generator
    {
        $saved = $context['saved'];
        $snapshot = $saved['snapshot'];
        $report = $snapshot['report'];
        $adjustment = $saved['adjustment'];
        $labels = array_column($report['rows'], 'label', 'source');
        yield [['PLANETA GAMER · Detalle mensual conservado'], [], true];
        yield [['Periodo · Bolivia', $context['period_label']], [], false];
        yield [['Versión', $saved['version'], 'Confirmado por', $saved['actor'].' · '.$saved['confirmed_at'].' Bolivia'], [], false];
        yield [['Generado por', $document['actor'], 'Generado · Bolivia', $document['generated_at']], [], false];
        yield [['Documento', $document['id']], [], false];
        yield [['Datos conservados de esta versión, sin consultar operaciones actuales. Ventas forma parte de Cobros; no sumarlos. Pendientes separados; no son pérdidas ni cobros.'], [], false];
        yield [['Fecha y hora · Bolivia', 'Clave', 'Origen', 'Registro', 'Responsable conservado', 'Cantidad', 'Referencia relacionada', 'Ventas · Bs', 'Cobros · Bs', 'ID responsable'], [], true];
        foreach ($records as $record) {
            yield [[$this->date($record['recorded_at']), $record['key'], $labels[$record['source']] ?? $record['source'], '#'.$record['record_id'], $record['actor_name'], $record['quantity'], $record['subject_id'] === null ? 'Sin referencia relacionada' : '#'.$record['subject_id'], $record['sales_cents'], $record['collections_cents'], $record['actor_id'] ?? 'Sin responsable'], [7, 8], false];
        }
        yield [['TOTAL CONSERVADO', 'Contribuciones', count($snapshot['records']), '', '', '', '', $report['sales_cents'], $report['collections_cents']], [7, 8], true];
        yield [['IDENTIFICACIÓN DE LA VERSIÓN'], [], true];
        yield [['Cierre', $saved['close']->id, 'Mes', $snapshot['month']], [], false];
        yield [['Huella SHA256 conservada', $saved['snapshot_hash']], [], false];
        yield [['Motivo del ajuste', $adjustment?->reason ?? 'No aplica al original'], [], false];
        yield [['Indicador', 'Original · Bs', 'Ajustes acumulados · Bs', 'Resultado · Bs'], [], true];
        yield [['Ventas', $saved['close']->snapshot['report']['sales_cents'], $saved['sales_adjustment_cents'], $report['sales_cents']], [1, 2, 3], false];
        yield [['Cobros', $saved['close']->snapshot['report']['collections_cents'], $saved['collections_adjustment_cents'], $report['collections_cents']], [1, 2, 3], false];
        yield [['PENDIENTES CONSERVADOS', 'Cantidad', count($snapshot['pending'])], [], true];
        yield [['Fecha y hora · Bolivia', 'Clave', 'Origen', 'Referencia', 'Responsable conservado', 'Estado conservado', 'ID responsable', 'Importe pendiente · Bs'], [], true];
        foreach ($snapshot['pending'] as $row) {
            yield [[$this->date($row['recorded_at']), $row['key'], $row['source'], $row['reference'], $row['actor_name'], $row['status'], $row['actor_id'] ?? 'Sin responsable', $row['amount_cents'] ?? 'No aplica'], $row['amount_cents'] === null ? [] : [7], false];
        }
        yield [['CAMBIOS DE ESTA VERSIÓN', 'Cantidad', count($saved['changes'])], [], true];
        yield [['Tipo', 'Clave', 'Sección', 'Antes · datos conservados JSON', 'Después · datos conservados JSON', '', '', '', 'Δ Ventas · Bs', 'Δ Cobros · Bs'], [], true];
        foreach ($saved['changes'] as $change) {
            yield [[$change['kind'], $change['key'], $change['section'], $this->json($change['before']), $this->json($change['after']), '', '', '', $change['sales_delta_cents'], $change['collections_delta_cents']], [8, 9], false];
        }
    }

    private function date(string $date): string
    {
        return CarbonImmutable::parse($date, 'UTC')->setTimezone('America/La_Paz')->format('d/m/Y H:i:s');
    }

    private function json(?array $value): string
    {
        return $value === null ? 'Sin registro' : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
