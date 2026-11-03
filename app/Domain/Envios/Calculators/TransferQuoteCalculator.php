<?php

namespace App\Domain\Envios\Calculators;

use App\Support\Bolivianos;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransferQuoteCalculator
{
    public const SCALE = 1000000;

    public function rate(string $value): int
    {
        Validator::make(['rate' => $value], ['rate' => ['required', 'regex:/\A[0-9]{1,3}(?:\.[0-9]{1,6})?\z/']])->validate();
        [$whole, $decimals] = array_pad(explode('.', $value, 2), 2, '');
        $scaled = (int) $whole * self::SCALE + (int) str_pad($decimals, 6, '0');
        if ($scaled < 1) {
            throw ValidationException::withMessages(['rate' => 'La tasa debe ser mayor a cero.']);
        }

        return $scaled;
    }

    public function calculate(string $direction, string $currency, string $amount, int $base, int $sell, int $buy): array
    {
        Validator::make(compact('direction', 'currency', 'amount'), [
            'direction' => ['required', Rule::in(['BO_BR', 'BR_BO'])], 'currency' => ['required', Rule::in(['BOB', 'BRL'])],
            'amount' => ['required', 'regex:/\A[0-9]{1,7}(?:\.[0-9]{1,2})?\z/'],
        ])->validate();
        if ($base < 1 || $buy < 1 || $buy > $base || $sell < $base || max($base, $buy, $sell) > 999999999) {
            throw ValidationException::withMessages(['amount' => 'Las tasas de compra/base/venta no son coherentes.']);
        }
        $input = Bolivianos::cents($amount);
        if ($input < 1 || $input > 999999999) {
            throw ValidationException::withMessages(['amount' => 'Importe fuera del rango admitido.']);
        }
        $commercial = $direction === 'BO_BR' ? $sell : $buy;
        $brl = $currency === 'BRL' ? $input : ($direction === 'BO_BR' ? intdiv($input * self::SCALE, $commercial) : $this->ceil($input * self::SCALE, $commercial));
        if ($brl < 1 || $brl > 999999999) {
            throw ValidationException::withMessages(['amount' => 'El importe convertido supera el límite técnico.']);
        }
        $bob = $currency === 'BOB' ? $input : ($direction === 'BO_BR' ? $this->ceil($brl * $commercial, self::SCALE) : intdiv($brl * $commercial, self::SCALE));
        $principal = $direction === 'BO_BR' ? $this->ceil($brl * $base, self::SCALE) : intdiv($brl * $base, self::SCALE);
        $margin = $direction === 'BO_BR' ? $bob - $principal : $principal - $bob;
        if (min($brl, $bob, $principal) < 1 || max($brl, $bob, $principal) > 999999999 || $margin < 0) {
            throw ValidationException::withMessages(['amount' => 'La conversión no es válida a centavos o supera el límite técnico.']);
        }

        return ['direction' => $direction, 'input_currency' => $currency, 'input_amount' => $amount,
            'bob_cents' => $bob, 'brl_cents' => $brl, 'principal_cents' => $principal, 'margin_cents' => $margin,
            'base_rate' => $base, 'commercial_rate' => $commercial];
    }

    private function ceil(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
