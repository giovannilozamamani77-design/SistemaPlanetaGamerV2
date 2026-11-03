<?php

namespace App\Domain\Envios\Calculators;

use App\Support\Bolivianos;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UsdRateCalculator
{
    public const SCALE = 1000000;

    public function prices(string $buy, string $sell): array
    {
        $values = [];
        foreach (['buy' => $buy, 'sell' => $sell] as $field => $value) {
            Validator::make([$field => $value], [$field => ['required', 'regex:/\A[0-9]{1,3}(?:\.[0-9]{1,6})?\z/']], [], [$field => $field === 'buy' ? 'compra' : 'venta'])->validate();
            [$whole,$fraction] = array_pad(explode('.', $value, 2), 2, '');
            $values[$field] = (int) $whole * self::SCALE + (int) str_pad($fraction, 6, '0');
            if ($values[$field] < 1) {
                throw ValidationException::withMessages([$field => 'La tasa debe ser mayor a cero.']);
            }
        }
        if ($values['buy'] > $values['sell']) {
            throw ValidationException::withMessages(['sell' => 'La venta debe ser igual o mayor que la compra.']);
        }

        return $values;
    }

    public function preview(string $buy, string $sell, string $amount): array
    {
        $prices = $this->prices($buy, $sell);
        Validator::make(['amount' => $amount], ['amount' => ['required', 'regex:/\A[0-9]{1,7}(?:\.[0-9]{1,2})?\z/']])->validate();
        $cents = Bolivianos::cents($amount);
        if ($cents < 1 || $cents > 999999999) {
            throw ValidationException::withMessages(['amount' => 'Ingresa un importe entre USD 0,01 y 9.999.999,99.']);
        }

        return ['usd_cents' => $cents, 'buy_total' => intdiv($cents * $prices['buy'], self::SCALE),
            'sell_total' => intdiv($cents * $prices['sell'] + self::SCALE - 1, self::SCALE), ...$prices];
    }

    public function label(int $value): string
    {
        return intdiv($value, self::SCALE).','.str_pad((string) ($value % self::SCALE), 6, '0', STR_PAD_LEFT);
    }

    public function input(int $value): string
    {
        return intdiv($value, self::SCALE).'.'.str_pad((string) ($value % self::SCALE), 6, '0', STR_PAD_LEFT);
    }
}
