<?php

namespace App\Http\Controllers\Accesorios;

use App\Domain\Accesorios\Queries\AccessorySaleQuery;
use App\Infrastructure\Accesorios\AccessoryReceiptPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AccessoryReceiptController
{
    public function __invoke(Request $request, int $sale, AccessorySaleQuery $query, AccessoryReceiptPdf $renderer): Response
    {
        $receipt = $query->receipt($request->user(), $sale);
        if ($request->routeIs('accesorios.recibo-pdf')) {
            $response = $renderer->render($receipt)->stream('Venta_Accesorios_'.$receipt['id'].'.pdf');
        } else {
            $response = response()->view('accesorios.receipt', ['receipt' => $receipt, 'autoPrint' => $request->boolean('print'), 'pdf' => false, 'logoData' => asset('branding/logoReciboPrestamo-print.jpg')]);
        }

        return $response->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
