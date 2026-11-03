<?php

namespace App\Domain\Accesorios\Calculators;

use App\Domain\Accesorios\Data\PricingInput;
use Illuminate\Validation\ValidationException;

class AverageCostCalculator
{
    public function receive(int $quantity, int $value, int $incoming, int $unitCost): array
    {
        if ($quantity < 0 || $value < 0 || ($quantity === 0 && $value !== 0) || $incoming < 1 || $unitCost < 1 || $quantity + $incoming > 100000 || $value + $incoming * $unitCost > PricingInput::MAX_CENTS) {
            throw ValidationException::withMessages(['quantity' => 'La entrada supera el límite de 100000 unidades o Bs 9999999.99 de inventario por producto.']);
        }

        return ['quantity' => $quantity + $incoming, 'value_cents' => $value + $incoming * $unitCost, 'entry_cents' => $incoming * $unitCost];
    }

    public function unitCostCeiling(int $quantity, int $value): int
    {
        return $quantity > 0 ? intdiv($value + $quantity - 1, $quantity) : 0;
    }

    public function display(int $quantity, int $value): string
    {
        if ($quantity === 0) {
            return '0';
        }
        $scaled = intdiv($value * 10000 + intdiv($quantity, 2), $quantity);

        return rtrim(rtrim(intdiv($scaled, 1000000).'.'.str_pad((string) ($scaled % 1000000), 6, '0', STR_PAD_LEFT), '0'), '.');
    }
}
