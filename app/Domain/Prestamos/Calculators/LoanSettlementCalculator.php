<?php

namespace App\Domain\Prestamos\Calculators;

use App\Domain\Prestamos\Models\Loan;
use Illuminate\Validation\ValidationException;

class LoanSettlementCalculator
{
    public function quote(Loan $loan): array
    {
        if (! in_array($loan->displayStatus(), ['ACTIVO', 'CON_MULTA'], true)) {
            throw ValidationException::withMessages(['payment_confirmed' => 'El préstamo ya no admite pago ordinario. Desde el día 8 de atraso es pérdida total.']);
        }
        $current = app(LoanTermCalculator::class)->current($loan);

        $fee = app(LoanLateFeeCalculator::class)->amount($loan);

        return ['late_fee_cents' => $fee, 'revision' => $current['revision'], 'capital_cents' => $loan->capital_cents, 'interest_cents' => $current['pending_cents'], 'paid_cents' => $loan->capital_cents + $current['pending_cents'] + $fee, 'due_date' => $current['due_date'], 'days' => $current['days']];
    }
}
