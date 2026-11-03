<?php

namespace App\Domain\Prestamos\Calculators;

use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\Loan;
use App\Support\Bolivianos;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LoanRecoveryCalculator
{
    public function quote(Loan $loan, int $expenses, ?int $price = null): array
    {
        if ($loan->displayStatus() !== 'PERDIDA_TOTAL') {
            throw ValidationException::withMessages(['price' => 'Solo se venden objetos en pérdida total que aún no tienen venta registrada.']);
        }
        $current = app(LoanTermCalculator::class)->current($loan);
        $minimum = $loan->capital_cents + $current['pending_cents'] + app(LoanLateFeeCalculator::class)->amount($loan) + $expenses;
        try {
            return $this->calculate($loan->capital_cents, $current['pending_cents'], app(LoanLateFeeCalculator::class)->amount($loan), $expenses, $price);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['price' => 'El precio mínimo de venta es Bs '.Bolivianos::formatCompact($minimum).'. No se permite vender por debajo de ese importe.']);
        }
    }

    public function calculate(int $capital, int $interest, int $fee, int $expenses, ?int $price = null): array
    {
        foreach ([$capital, $interest, $fee, $expenses] as $amount) {
            if ($amount < 0 || $amount > LoanInput::MAX_CENTS) {
                throw new InvalidArgumentException('Los importes deben ser positivos y estar dentro del límite permitido.');
            }
        }
        $invested = $capital + $expenses;
        $loanProfit = $interest + $fee;
        $minimum = $invested + $loanProfit;
        if ($capital < 1 || $minimum > LoanInput::MAX_CENTS || ($price !== null && ($price < $minimum || $price > LoanInput::MAX_CENTS))) {
            throw new InvalidArgumentException('El precio no puede quedar por debajo del mínimo recuperable ni superar el límite permitido.');
        }

        return ['capital_cents' => $capital, 'interest_cents' => $interest, 'late_fee_cents' => $fee, 'expense_cents' => $expenses, 'invested_cents' => $invested, 'minimum_cents' => $minimum, 'price_cents' => $price, 'loan_profit_cents' => $loanProfit, 'sale_profit_cents' => $price === null ? null : $price - $minimum, 'total_profit_cents' => $price === null ? null : $price - $invested];
    }
}
