<?php

namespace App\Domain\Tarjetas\Calculators;

use App\Domain\Tarjetas\Data\CardInventoryInput;
use Illuminate\Validation\ValidationException;

class CardPurchasePreview
{
    public function calculate(mixed $quantity, mixed $price): ?int
    {
        if (filter_var($quantity, FILTER_VALIDATE_INT) === false || (int) $quantity < 1 || (int) $quantity > 1000000) {
            return null;
        }
        try {
            return (int) $quantity * CardInventoryInput::money($price, 'unit_price');
        } catch (ValidationException) {
            return null;
        }
    }
}
