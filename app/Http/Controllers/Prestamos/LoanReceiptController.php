<?php

namespace App\Http\Controllers\Prestamos;

use App\Domain\Prestamos\Queries\LoanQuery;

class LoanReceiptController
{
    public function __invoke(int $loan)
    {
        return response()->view('prestamos.receipt', ['detail' => app(LoanQuery::class)->detail(auth()->user(), $loan), 'autoPrint' => request()->boolean('print')])->header('Cache-Control', 'private, no-store');
    }
}
