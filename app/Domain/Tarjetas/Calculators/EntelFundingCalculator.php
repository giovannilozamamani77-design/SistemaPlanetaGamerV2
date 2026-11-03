<?php

namespace App\Domain\Tarjetas\Calculators;

use App\Domain\Tarjetas\Data\CardInventoryInput;
use Illuminate\Validation\ValidationException;

class EntelFundingCalculator
{
    public const MAX_CENTS = 99999999999;

    public function funding(int $paid, int $credited): array
    {
        if ($paid < 500000) {
            throw ValidationException::withMessages(['paid_amount' => 'La carga propia a Entel requiere un pago mínimo de Bs 5000.']);
        }
        if ($credited < $paid) {
            throw ValidationException::withMessages(['credited_amount' => 'El saldo acreditado no puede ser menor que el monto pagado. Revisa los datos.']);
        }
        $bonus = $credited - $paid;

        return ['bonus_cents' => $bonus, 'bonus_rate_bps' => intdiv($bonus * 10000 + intdiv($paid, 2), $paid)];
    }

    public function preview(mixed $paid, mixed $credited): ?array
    {
        try {
            return $this->funding(
                CardInventoryInput::money($paid, 'paid_amount'),
                CardInventoryInput::money($credited, 'credited_amount'),
            );
        } catch (ValidationException) {
            return null;
        }
    }

    public function balance(int $before, int $credited): int
    {
        if ($before > self::MAX_CENTS - $credited) {
            throw ValidationException::withMessages(['credited_amount' => 'El saldo resultante supera el importe admitido.']);
        }

        return $before + $credited;
    }
}
