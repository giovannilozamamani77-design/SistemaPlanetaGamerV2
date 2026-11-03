<?php

namespace App\Infrastructure\Prestamos;

use App\Domain\Prestamos\Models\LoanTermChange;
use Barryvdh\DomPDF\PDF;

class LoanReceiptPdf
{
    public function render(array $detail): PDF
    {
        return $this->renderView('prestamos.receipt', ['detail' => $detail]);
    }

    public function renderSale(array $detail): PDF
    {
        return $this->renderView('prestamos.sale-receipt', ['sale' => $detail['sale'], 'loanId' => $detail['id']]);
    }

    public function renderSettlement(array $detail): PDF
    {
        return $this->renderView('prestamos.settlement-receipt', ['detail' => $detail]);
    }

    public function renderTerm(LoanTermChange $event): PDF
    {
        return $this->renderView('prestamos.term-receipt', ['event' => $event]);
    }

    private function renderView(string $view, array $data): PDF
    {
        /** @var PDF $pdf */
        $pdf = app('dompdf.wrapper');
        $logo = 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path('branding/logoReciboPrestamo-print.jpg')));

        return $pdf->setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isFontSubsettingEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
            'defaultMediaType' => 'print',
            'chroot' => [public_path()],
        ])->setPaper('letter', 'portrait')->loadView($view, $data + ['logoData' => $logo, 'autoPrint' => false]);
    }
}
