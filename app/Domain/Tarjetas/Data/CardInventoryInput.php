<?php

namespace App\Domain\Tarjetas\Data;

use App\Support\Bolivianos;
use Illuminate\Validation\ValidationException;

class CardInventoryInput
{
    public const COMPANIES = ['ENTEL' => 'Entel', 'TIGO' => 'Tigo', 'VIVA' => 'Viva'];

    public static function money(mixed $amount, string $field, bool $allowZero = false): int
    {
        try {
            if (! is_string($amount) && ! is_int($amount)) {
                throw ValidationException::withMessages([$field => 'Ingresa un monto con hasta dos decimales.']);
            }
            $value = Bolivianos::cents(trim((string) $amount));
        } catch (ValidationException) {
            throw ValidationException::withMessages([$field => 'Ingresa un monto válido con hasta dos decimales.']);
        }
        if (! $allowZero && $value < 1) {
            throw ValidationException::withMessages([$field => 'El monto debe ser mayor que cero.']);
        }

        return $value;
    }
}
