<?php

namespace App\Http\Controllers\Prestamos;

use App\Domain\Prestamos\Queries\LoanQuery;
use Illuminate\Http\Response;

class LoanTermReceiptController
{
    public function __invoke(int $change): Response
    {
        return response()->view('prestamos.term-receipt', ['event' => app(LoanQuery::class)->termReceipt(auth()->user(), $change)])->header('Cache-Control', 'private, no-store');
    }
}
