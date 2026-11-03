<?php

namespace App\Domain\Tarjetas\Calculators;

class CardInitialCountPreview
{
    public function units(array $rows): ?int
    {
        $total = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return null;
            }
            $quantity = $row['quantity'] ?? 0;
            if ($quantity === null || $quantity === '') {
                $quantity = 0;
            }
            if (! is_scalar($quantity) || filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 0 || (int) $quantity > 1000000) {
                return null;
            }
            $total += (int) $quantity;
        }

        return $total;
    }
}
