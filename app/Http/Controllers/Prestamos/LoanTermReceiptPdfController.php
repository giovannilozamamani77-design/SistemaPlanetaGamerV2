<?php

namespace App\Http\Controllers\Prestamos;

use App\Domain\Prestamos\Queries\LoanQuery;
use App\Infrastructure\Prestamos\LoanReceiptPdf;
use Illuminate\Http\Response;

class LoanTermReceiptPdfController
{
    public function __invoke(int $change, LoanQuery $query, LoanReceiptPdf $renderer): Response
    {
        $event = $query->termReceipt(auth()->user(), $change);

        return $renderer->renderTerm($event)->stream('Prestamo_'.$event->loan_id.'_Operacion_'.$event->id.'.pdf')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
