<?php

namespace App\Infrastructure\Reportes;

use Barryvdh\DomPDF\PDF;
use Dompdf\Canvas;
use Dompdf\FontMetrics;

class ReportSummaryPdf
{
    public function render(array $report, array $document): PDF
    {
        /** @var PDF $pdf */
        $pdf = app('dompdf.wrapper');
        $logoPath = public_path('branding/logoReciboPrestamo-print.jpg');
        $pdf->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
            'isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Sans', 'defaultMediaType' => 'print', 'chroot' => [public_path()]])
            ->setPaper('letter', 'portrait')->loadView('reportes.summary-pdf', ['report' => $report, 'document' => $document]);
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $dompdf->getCanvas()->page_script(function (int $pageNumber, int $pageCount, Canvas $canvas, FontMetrics $metrics) use ($logoPath): void {
            $normal = $metrics->getFont('DejaVu Sans', 'normal');
            $bold = $metrics->getFont('DejaVu Sans', 'bold');
            $canvas->text(40, 21, 'PLANETA GAMER', $bold, 9, [0.1, 0.16, 0.23]);
            $canvas->text(40, 34, 'Resumen administrativo', $normal, 7.5, [0.35, 0.42, 0.48]);
            if (is_file($logoPath)) {
                $canvas->image($logoPath, 508, 15, 64, 38);
            }
            $canvas->line(40, 57, 572, 57, [0.05, 0.71, 0.74], 1.4);
        });
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $dompdf->getCanvas()->page_text(435, 765, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.35, 0.4, 0.45]);

        return $pdf;
    }
}
