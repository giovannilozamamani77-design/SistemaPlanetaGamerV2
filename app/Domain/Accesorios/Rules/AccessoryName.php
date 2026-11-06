<?php

namespace App\Domain\Accesorios\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AccessoryName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\p{L}/u', $value)) {
            $fail('Escribe un nombre que incluya letras; también puedes usar números, modelo, capacidad o potencia.');
        }
    }
}
