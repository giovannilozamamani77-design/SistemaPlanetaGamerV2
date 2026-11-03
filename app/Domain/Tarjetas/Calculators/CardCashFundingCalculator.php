<?php

namespace App\Domain\Tarjetas\Calculators;

use App\Domain\Tarjetas\Data\CardInventoryInput;
use Illuminate\Validation\ValidationException;

class CardCashFundingCalculator
{
    public function after(int $before, mixed $amount): int
    {
        $cents = CardInventoryInput::money($amount, 'amount');
        if ($cents > CardSaleCalculator::MAX_CENTS || $before > CardSaleCalculator::MAX_CENTS - $cents) {
            throw ValidationException::withMessages(['amount' => 'El saldo resultante supera el máximo técnico admitido.']);
        }

        return $before + $cents;
    }

    public function preview(?int $before, mixed $amount): ?int
    {
        if ($before === null || ! filled($amount)) {
            return null;
        }
        try {
            return $this->after($before, $amount);
        } catch (ValidationException) {
            return null;
        }
    }
}
