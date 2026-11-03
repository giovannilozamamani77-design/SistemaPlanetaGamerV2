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

class ReportRecordsFile
{
    public function render(array $context, array $document, iterable $records, string $format): string
    {
        $folder = storage_path('app/private/report-exports');
        File::ensureDirectoryExists($folder, 0700);
        $path = tempnam($folder, 'records-');
        if ($path === false) {
            throw new RuntimeException('No se pudo preparar la exportación del detalle.');
        }
        chmod($path, 0600);
        $writer = null;
        $stream = null;
        try {
            if ($format === 'XLSX') {
                $options = new Options;
                $options->setTempFolder($folder);
                $options->setColumnWidthForRange(24, 1, 10);
                $options->setColumnWidth(60, 7);
                $options->setColumnWidth(12, 6);
                $options->setColumnWidthForRange(20, 8, 10);
                foreach ([1, 6] as $row) {
                    $options->mergeCells(0, $row, 9, $row);
                }
                foreach ([2, 5] as $row) {
                    $options->mergeCells(1, $row, 9, $row);
                }
                foreach ([3, 4] as $row) {
                    $options->mergeCells(3, $row, 9, $row);
                }
                $options->DEFAULT_ROW_STYLE = (new Style)->setFontName('Calibri')->setFontSize(10)->setShouldWrapText();
                $writer = new Writer($options);
                $writer->openToFile($path);
                $writer->getCurrentSheet()->setName('Detalle');
                $view = new SheetView;
                $view->setShowGridLines(false);
                $view->setFreezeRow(8);
                $writer->getCurrentSheet()->setSheetView($view);
            } else {
                $stream = fopen($path, 'w+b');
                if ($stream === false || fwrite($stream, "\xEF\xBB\xBF") === false) {
                    throw new RuntimeException('No se pudo preparar el CSV.');
                }
            }
            foreach ($this->rows($context, $document, $records) as [$values, $money, $heading]) {
                $values = array_pad($values, 10, '');
                if ($writer) {
                    $cells = [];
                    foreach ($values as $index => $value) {
                        $style = in_array($index, $money, true) ? (new Style)->setFormat('"Bs" #,##0.00;[Red]-"Bs" #,##0.00') : null;
                        $cells[] = in_array($index, $money, true) ? Cell::fromValue($value / 100, $style) : (is_string($value) ? new StringCell($value, null) : Cell::fromValue($value, null));
                    }
                    $writer->addRow(new Row($cells, $heading ? (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('166772')->setShouldWrapText() : null));
                } else {
                    foreach ($values as $index => $value) {
                        if (in_array($index, $money, true)) {
                            $values[$index] = ($value < 0 ? '-' : '').intdiv(abs($value), 100).'.'.str_pad((string) (abs($value) % 100), 2, '0', STR_PAD_LEFT);
                        } elseif (is_string($value) && preg_match('/^(?:[\p{Z}\x00-\x20]*[=+\-@]|[\t\r\n])/u', $value)) {
                            $values[$index] = "'".$value;
                        }
                    }
                    if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false) {
                        throw new RuntimeException('No se pudo escribir el CSV.');
                    }
                }
            }
            if ($writer) {
                $writer->close();
                $writer = null;
            }
            if (is_resource($stream)) {
                fclose($stream);
                $stream = null;
            }
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('No se pudo leer la exportación.');
            }

            return $bytes;
        } finally {
            try {
                $writer?->close();
                if (is_resource($stream)) {
                    fclose($stream);
                }
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    protected function rows(array $context, array $document, iterable $records): \Generator
    {
        yield [['PLANETA GAMER · Detalle de registros'], [], true];
        yield [['Periodo · Bolivia', $context['period_label']], [], false];
        yield [['Indicador', $context['metric_label'], 'Origen', $context['label']], [], false];
        yield [['Generado por', $document['actor'], 'Generado · Bolivia', $document['generated_at']], [], false];
        yield [['Documento', $document['id']], [], false];
        yield [['Consulta de registros vigentes al generar. No constituye cierre mensual. Las ventas forman parte de los cobros; no se suman entre sí.'], [], false];
        yield [['Fecha y hora · Bolivia', 'Clave', 'Origen', 'Referencia', 'Responsable', 'Cantidad', 'Detalle', 'Ventas · Bs', 'Cobros · Bs', 'Importe seleccionado · Bs'], [], true];
        foreach ($records as $record) {
            $items = array_map(fn ($item) => $item['label'].' × '.$item['quantity'], $record['items']);
            yield [[$record['recorded_at'], $record['key'], $record['label'], $record['reference'], $record['actor'], $record['quantity'], implode(' · ', [...$items, $record['description']]), $record['sales_cents'], $record['collections_cents'], $record['amount_cents']], [7, 8, 9], false];
        }
        yield [['TOTAL DEL INDICADOR', 'Registros', $context['records_count'], '', '', '', '', '', '', $context['total_cents']], [9], true];
    }
}
