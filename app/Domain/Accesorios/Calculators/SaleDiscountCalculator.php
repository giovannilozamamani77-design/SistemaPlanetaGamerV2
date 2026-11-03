<?php

namespace App\Domain\Accesorios\Calculators;

use App\Domain\Accesorios\Models\CommercialRules;
use App\Support\Bolivianos;
use Illuminate\Validation\ValidationException;

class SaleDiscountCalculator
{
    public function net(int $gross, int $cost, int $discount): int
    {
        if ($discount < 0 || $gross - $discount < $cost) {
            throw ValidationException::withMessages(['cart' => 'El descuento supera el permitido. Cada producto debe cubrir su costo.']);
        }

        return $gross - $discount;
    }

    public function limited(int $gross, int $cost, int $discount, string $kind, int $quantity, ?array $rules = null): int
    {
        $rules ??= CommercialRules::current('DISCOUNTS')['rules'];
        $maximum = $kind === 'PHONE' ? $rules['phone_max_cents'] : CommercialRules::bracket(intdiv($gross, $quantity), $rules['ranges']) * $quantity;
        if ($discount > $maximum) {
            throw ValidationException::withMessages(['cart' => 'El descuento supera el máximo permitido: Bs '.Bolivianos::formatCompact($maximum).' para esta línea.']);
        }

        return $this->net($gross, $cost, $discount);
    }

    public function amounts(array $line): array
    {
        $subtotal = $line['unit_price_cents'] * $line['quantity'];

        return ['subtotal_cents' => $subtotal, 'discount_cents' => $subtotal - $line['total_cents']];
    }

    public function summary(array $lines): array
    {
        $subtotal = 0;
        $discount = 0;
        foreach ($lines as $line) {
            $amounts = $this->amounts($line);
            $subtotal += $amounts['subtotal_cents'];
            $discount += $amounts['discount_cents'];
        }

        return ['subtotal_cents' => $subtotal, 'discount_cents' => $discount];
    }
}
