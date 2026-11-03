<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class Bolivianos
{
    public static function cents(string $amount): int
    {
        if (! preg_match('/\A([0-9]{1,9})(?:\.([0-9]{1,2}))?\z/', $amount, $m)) {
            throw ValidationException::withMessages(['amount' => 'Usa un importe válido con hasta dos decimales.']);
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function formatCompact(int $cents): string
    {
        return $cents % 100 === 0 ? (string) intdiv($cents, 100) : self::format($cents);
    }

    public static function format(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
