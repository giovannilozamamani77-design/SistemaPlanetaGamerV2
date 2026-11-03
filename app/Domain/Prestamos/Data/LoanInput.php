<?php

namespace App\Domain\Prestamos\Data;

use App\Support\Bolivianos;
use Illuminate\Validation\ValidationException;

class LoanInput
{
    public const MAX_CENTS = 99999999999;

    public static function money(mixed $amount, string $field, bool $zero = false): int
    {
        try {
            if (! is_string($amount) && ! is_int($amount)) {
                throw new \InvalidArgumentException;
            }$c = Bolivianos::cents(trim((string) $amount));
            if (! $zero && $c < 1) {
                throw new \InvalidArgumentException;
            }

            return $c;
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Ingresa un monto válido con hasta dos decimales.']);
        }
    }

    public static function normalize(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($value)));
    }
}
