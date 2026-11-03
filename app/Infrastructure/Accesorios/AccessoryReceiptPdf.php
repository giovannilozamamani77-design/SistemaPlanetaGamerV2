<?php

namespace App\Infrastructure\Accesorios;

use Barryvdh\DomPDF\PDF;

class AccessoryReceiptPdf
{
    public function render(array $receipt): PDF
    {
        /** @var PDF $pdf */
        $pdf = app('dompdf.wrapper');
        $logo = 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path('branding/logoReciboPrestamo-print.jpg')));

        return $pdf->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Sans', 'defaultMediaType' => 'print', 'chroot' => [public_path()]])->setPaper('letter', 'portrait')->loadView('accesorios.receipt', ['receipt' => $receipt, 'logoData' => $logo, 'autoPrint' => false, 'pdf' => true]);
    }
}
