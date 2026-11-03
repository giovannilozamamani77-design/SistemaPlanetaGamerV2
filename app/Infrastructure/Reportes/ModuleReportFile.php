<?php

namespace App\Infrastructure\Reportes;

use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use ZipArchive;

class ModuleReportFile
{
    public function render(array $report, string $format): string
    {
        $images = app(ModuleReportImages::class)->render($report);
        if ($format === 'PDF') {
            return app('dompdf.wrapper')->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
                ->setPaper('letter')->loadView('reportes.module-pdf', compact('report', 'images'))->output();
        }
        $folder = storage_path('app/private/report-exports');
        File::ensureDirectoryExists($folder, 0700);
        $path = tempnam($folder, 'module-');
        if ($path === false) {
            throw new RuntimeException('No se pudo crear el archivo.');
        }
        chmod($path, 0600);
        $options = new Options;
        $options->setTempFolder($folder);
        $options->setColumnWidth(45, 1);
        $options->setColumnWidthForRange(24, 2, 4);
        $writer = new Writer($options);
        try {
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName('Resumen');
            $add = function (array $values) use ($writer): void {
                $writer->addRow(new Row(array_map(fn ($v) => is_string($v) ? new StringCell($v, null) : Cell::fromValue($v), $values)));
            };
            $add(['PLANETA GAMER · '.$report['definition']['label']]);
            $add(['Mes consultado', $report['month']]);
            $add(['Periodo Bolivia', $report['period']['label']]);
            $add(['Cobrado · Bs', $report['collections_cents'] / 100]);
            $add([$report['definition']['margin_label'].' · Bs', $report['margin']['known_cents'] / 100]);
            $add(['Registros con resultado pendiente', $report['margin']['pending']]);
            $add(['Capital recuperado identificado · Bs', $report['margin']['capital_recovered_cents'] / 100]);
            $add(['Comisiones devengadas · Bs', $report['margin']['commissions_cents'] === null ? 'No corresponde' : $report['margin']['commissions_cents'] / 100]);
            $add(['Tras comisiones · Bs', $report['margin']['after_commissions_cents'] === null ? 'Pendiente / no corresponde' : $report['margin']['after_commissions_cents'] / 100]);
            $add(['Estado del mes', $report['period']['is_current'] ? 'En curso; comparación con mes anterior completo' : 'Mes terminado']);
            $add(['Registros sin comisión conservada', $report['margin']['commission_pending']]);
            $add(['Comparación anterior', $report['period']['previous_label']]);
            $add(['Cobros mes anterior · Bs', ($report['comparison']['collections']['previous_cents'] ?? 0) / 100]);
            $add(['Diferencia cobros · Bs', ($report['comparison']['collections']['difference_cents'] ?? 0) / 100]);
            $add(['Advertencia', $report['definition']['note']]);
            $add(['No equivale a utilidad neta; excluye gastos generales. Consulta operativa, no cierre mensual congelado.']);
            $add(['Origen', 'Cobrado · Bs', 'Resultado conocido · Bs', 'Pendientes']);
            foreach ($report['rows'] as $key => $row) {
                $add([$row['label'], $row['collections_cents'] / 100, ($report['margin']['parts'][$key]['known_cents'] ?? 0) / 100, $report['margin']['parts'][$key]['pending'] ?? 0]);
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Datos gráficos');
            $add(['Día', 'Cobros · Bs', 'Resultado conocido · Bs']);
            foreach ($report['trend'] as $day) {
                $add([$day['day'], $day['collections_cents'] / 100, $day['margin_cents'] / 100]);
            }
            $add(['Distribución', 'Cobrado · Bs']);
            foreach ($report['charts']['distribution']['labels'] as $i => $label) {
                $add([$label, $report['charts']['distribution']['datasets'][0]['data'][$i]]);
            }
            $writer->close();
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('No se pudo completar Excel.');
            }
            try {
                $zip->addFromString('xl/media/trend.png', $images['trend']);
                $zip->addFromString('xl/media/distribution.png', $images['distribution']);
                $ct = $zip->getFromName('[Content_Types].xml');
                $ct = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/></Types>', $ct);
                $zip->addFromString('[Content_Types].xml', $ct);
                $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
                $sheet = str_replace('</worksheet>', '<drawing xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rIdModuleCharts"/></worksheet>', $sheet);
                $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
                $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdModuleCharts" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
                $drawing = '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">';
                $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
                foreach (['trend', 'distribution'] as $i => $name) {
                    $id = $i + 1;
                    $row = $i * 23;
                    $drawing .= '<xdr:oneCellAnchor><xdr:from><xdr:col>5</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$row.'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:ext cx="7620000" cy="3200400"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="'.$id.'" name="'.$name.'"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId'.$id.'"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
                    $rels .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/'.$name.'.png"/>';
                }
                $zip->addFromString('xl/drawings/drawing1.xml', $drawing.'</xdr:wsDr>');
                $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $rels.'</Relationships>');
            } finally {
                $zip->close();
            }

            return file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
