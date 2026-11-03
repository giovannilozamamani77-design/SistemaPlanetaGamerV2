<?php

namespace App\Domain\Prestamos\Calculators;

use App\Domain\Prestamos\Models\Loan;

class LoanLateFeeCalculator
{
    public function amount(Loan $loan): int
    {
        if (! in_array($loan->displayStatus(), ['CON_MULTA', 'PERDIDA_TOTAL'], true)) {
            return 0;
        }

        return (int) ($loan->terms_snapshot['receipt_terms']['late_fee_cents'] ?? 4000);
    }
}
