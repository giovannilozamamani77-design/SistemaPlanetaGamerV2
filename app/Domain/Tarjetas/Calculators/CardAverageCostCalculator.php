<?php

namespace App\Domain\Tarjetas\Calculators;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class CardAverageCostCalculator
{
    public function receive(int $quantity, ?int $value, int $added, ?int $cost): ?int
    {
        if ($quantity < 0 || $added < 0 || ($value !== null && $value < 0) || ($cost !== null && $cost < 0)) {
            throw new InvalidArgumentException('Cantidades y costos no pueden ser negativos.');
        }
        if ($quantity + $added === 0) {
            return 0;
        }
        if ($added === 0) {
            return $quantity === 0 ? 0 : $value;
        }
        if ($cost === null || ($quantity > 0 && $value === null)) {
            return null;
        }

        return BigInteger::of($quantity === 0 ? 0 : $value)->plus($cost)->toInt();
    }

    /** @return array{consumed: ?int, remaining: ?int} */
    public function consume(int $quantity, ?int $value, int $used): array
    {
        if ($quantity < 0 || $used < 0 || $used > $quantity || ($value !== null && $value < 0)) {
            throw new InvalidArgumentException('Consumo fuera de las existencias disponibles.');
        }
        if ($used === 0) {
            return ['consumed' => 0, 'remaining' => $quantity === 0 ? 0 : $value];
        }
        $cost = $value === null ? null : ($used === $quantity ? $value : BigInteger::of($value)->multipliedBy($used)->dividedBy($quantity, RoundingMode::HalfUp)->toInt());

        return ['consumed' => $cost, 'remaining' => $used === $quantity ? 0 : ($value === null ? null : $value - $cost)];
    }

    public function afterCount(int $quantity, ?int $value, int $counted): ?int
    {
        return $counted <= $quantity
            ? $this->consume($quantity, $value, $quantity - $counted)['remaining']
            : $this->receive($quantity, $value, $counted - $quantity, null);
    }
}
