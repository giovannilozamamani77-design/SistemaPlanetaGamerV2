<?php

namespace App\Http\Controllers\Prestamos;

use App\Domain\Prestamos\Queries\LoanQuery;
use App\Infrastructure\Prestamos\LoanReceiptPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class LoanReceiptPdfController
{
    public function __invoke(int $loan, LoanQuery $query, LoanReceiptPdf $renderer): Response|RedirectResponse
    {
        $detail = $query->detail(auth()->user(), $loan);

        abort_if(! request()->boolean('original') && $detail['status'] === 'ANULADO', 409, 'Este préstamo está anulado. Consulta su detalle y el recibo original como historial.');

        if (! request()->boolean('original') && $detail['sale']) {
            return $renderer->renderSale($detail)->stream('Venta_Objeto_'.$detail['id'].'.pdf')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
        }

        if (! request()->boolean('original') && $detail['settlement']) {
            return $renderer->renderSettlement($detail)->stream('Prestamo_'.$detail['id'].'_Cancelacion.pdf')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
        }

        if (! request()->boolean('original') && $detail['current']['revision'] > 0) {
            return redirect()->route('prestamos.renovacion-pdf', ['change' => $detail['current']['revision']])->header('Cache-Control', 'private, no-store');
        }

        return $renderer->render($detail)->stream('Prestamo_'.$detail['id'].'.pdf')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
