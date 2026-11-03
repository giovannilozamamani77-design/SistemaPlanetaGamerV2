<?php

namespace App\Domain\Accesorios\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AccessoryName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\p{L}/u', $value) || preg_match('/\p{N}/u', $value)) {
            $fail('Escribe el nombre en texto, sin números. Coloca el modelo, capacidad o potencia en Detalle.');
        }
    }
}
