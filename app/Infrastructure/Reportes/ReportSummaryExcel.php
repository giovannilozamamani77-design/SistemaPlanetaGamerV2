<?php

namespace App\Infrastructure\Reportes;

use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

class ReportSummaryExcel
{
    public function render(array $report, array $document): string
    {
        $folder = storage_path('app/private/report-exports');
        File::ensureDirectoryExists($folder, 0700);
        $path = tempnam($folder, 'summary-');
        if ($path === false) {
            throw new RuntimeException('No se pudo preparar la exportación de Excel.');
        }
        chmod($path, 0600);
        $options = new Options;
        $options->setTempFolder($folder);
        $options->setColumnWidth(48, 1);
        $options->setColumnWidthForRange(24, 2, 4);
        $options->DEFAULT_ROW_STYLE = (new Style)->setFontName('Calibri')->setFontSize(11)->setShouldWrapText();
        $writer = new Writer($options);
        try {
            $writer->openToFile($path);
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Resumen');
            $view = new SheetView;
            $view->setShowGridLines(false);
            $view->setFreezeRow(11);
            $sheet->setSheetView($view);
            $heading = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('166772')->setShouldWrapText();
            $title = (new Style)->setFontBold()->setFontSize(18)->setFontColor('FFFFFF')->setBackgroundColor('30214C');
            $money = (new Style)->setFormat('"Bs" #,##0.00;[Red]-"Bs" #,##0.00');
            $this->wideRow($writer, $options, 'PLANETA GAMER · Resumen de Reportes', $title, 34);
            foreach ([2, 3, 4, 5] as $metaRow) {
                $options->mergeCells(1, $metaRow, 3, $metaRow);
            }
            $this->row($writer, [$document['mode_label'].' consultado', $report['period']['label']]);
            $this->row($writer, ['Periodo anterior', $report['comparison']['available'] ? $report['period']['previous_label'] : 'Sin comparación disponible']);
            $this->row($writer, ['Generado por', $document['actor']]);
            $this->row($writer, ['Generado · Bolivia', $document['generated_at']]);
            $this->wideRow($writer, $options, 'Documento '.$document['id']);
            if (isset($report['monthly_adjustment'])) {
                $this->wideRow($writer, $options, 'Cierre ajustado v'.$report['monthly_adjustment']['sequence'].' · '.$report['monthly_adjustment']['actor'].' · '.$report['monthly_adjustment']['confirmed_at'].' Bolivia', $heading, 32);
                $this->wideRow($writer, $options, 'Motivo: '.$report['monthly_adjustment']['reason'], null, max(44, max(substr_count($report['monthly_adjustment']['reason'], "\n") + 1, (int) ceil(mb_strlen($report['monthly_adjustment']['reason']) / 95)) * 16));
                $this->row($writer, ['Indicador', 'Original · Bs', 'Ajustes · Bs', 'Resultado · Bs'], $heading);
                foreach (['sales' => 'Ventas', 'collections' => 'Cobros'] as $metric => $label) {
                    $this->row($writer, [$label, $report['monthly_adjustment']['original_'.$metric.'_cents'] / 100, $report['monthly_adjustment'][$metric.'_adjustment_cents'] / 100, $report[$metric.'_cents'] / 100], null, [1 => $money, 2 => $money, 3 => $money]);
                }
                $this->wideRow($writer, $options, 'Versión conservada. Comparación con el mes anterior observado en el cierre original. '.$report['monthly_adjustment']['pending_count'].' pendientes observados.', null, 32);
            }
            if (isset($report['monthly_close'])) {
                $this->wideRow($writer, $options, 'Cierre original #'.$report['monthly_close']['id'].' · '.$report['monthly_close']['month'].' · Confirmó '.$report['monthly_close']['actor'].' · '.$report['monthly_close']['confirmed_at'].' Bolivia · '.$report['monthly_close']['pending_count'].' pendientes al confirmar.', null, 40);
            }
            $this->wideRow($writer, $options, $report['period']['is_current'] ? 'Periodo en curso: se compara lo registrado con el periodo anterior completo.' : (isset($report['monthly_adjustment']) ? 'Resultado conservado de esta versión; los cambios posteriores no alteran este archivo.' : (isset($report['monthly_close']) ? 'Datos originales conservados al confirmar. No se recalculan con operaciones posteriores.' : 'Consulta de operaciones vigentes al generar. No constituye cierre mensual.')), (new Style)->setBackgroundColor('FFF7D6')->setShouldWrapText(), 30);
            $this->row($writer, []);
            $this->wideRow($writer, $options, 'Ventas y cobros', $heading);
            $this->row($writer, ['Indicador', 'Consultado · Bs', 'Anterior · Bs', 'Diferencia · Bs'], $heading);
            foreach (['sales' => 'Ventas registradas', 'collections' => 'Cobros registrados'] as $metric => $label) {
                $comparison = $report['comparison'][$metric];
                $this->row($writer, [$label, $report[$metric.'_cents'] / 100, $comparison === null ? 'No disponible' : $comparison['previous_cents'] / 100, $comparison === null ? 'Sin comparación disponible' : $comparison['difference_cents'] / 100], null, [1 => $money, 2 => $money, 3 => $money]);
            }
            $this->wideRow($writer, $options, 'Ventas ya forma parte de Cobros: no se suman. Cobros incluye capital recuperado de Préstamos; no representa ganancias.', null, 32);
            $this->row($writer, []);
            $this->wideRow($writer, $options, 'Desglose del periodo consultado', $heading);
            $this->row($writer, ['Origen', 'Registros', 'Ventas · Bs', 'Cobros · Bs'], $heading);
            foreach ($report['rows'] as $row) {
                $this->row($writer, [$row['label'], $row['operations'], $row['sales_cents'] / 100, $row['collections_cents'] / 100], null, [2 => $money, 3 => $money]);
            }
            $this->wideRow($writer, $options, 'Las ventas mixtas se separan por tipo sin duplicar importes; Registros no equivale a tickets únicos.', null, 30);
            $this->row($writer, []);
            if (($report['balance_scope'] ?? null) === 'excluded_monthly') {
                $this->wideRow($writer, $options, 'Cierre mensual: no incluye saldos actuales como saldos históricos. Pendientes separados, sin reconocerlos como pérdidas.', null, 32);
            } else {
                $this->wideRow($writer, $options, 'Dinero disponible al generar', $heading);
                $this->wideRow($writer, $options, 'Saldos actuales por fondo, no históricos ni conciliados con el banco. No se suman fondos ni saldos digitales Entel.', null, 32);
                $this->row($writer, ['Fondo / componente', 'Disponible · Bs', 'Detalle'], $heading);
                foreach ($report['balances'] as $balance) {
                    $index = $writer->getWrittenRowCount() + 1;
                    $options->mergeCells(2, $index, 3, $index);
                    $this->row($writer, [$balance['label'], $balance['amount_cents'] === null ? 'No disponible' : $balance['amount_cents'] / 100, $balance['note']], null, [1 => $money], 44);
                }
            }
            $writer->close();
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('No se pudo leer la exportación de Excel.');
            }

            return $bytes;
        } finally {
            try {
                $writer->close();
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function row(Writer $writer, array $values, ?Style $style = null, array $styles = [], float $height = 28): void
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $cellStyle = $styles[$index] ?? null;
            $cells[] = is_string($value) ? new StringCell($value, $cellStyle) : Cell::fromValue($value, $cellStyle);
        }
        $row = new Row($cells, $style);
        $row->setHeight($height);
        $writer->addRow($row);
    }

    private function wideRow(Writer $writer, Options $options, string $text, ?Style $style = null, float $height = 26): void
    {
        $index = $writer->getWrittenRowCount() + 1;
        $options->mergeCells(0, $index, 3, $index);
        $this->row($writer, [$text], $style, height: $height);
    }
}
