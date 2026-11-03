<?php

namespace App\Domain\Envios\Calculators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BrazilRateCalculator
{
    public function input(int $rate): string
    {
        return intdiv($rate, TransferQuoteCalculator::SCALE).'.'.str_pad((string) ($rate % TransferQuoteCalculator::SCALE), 6, '0', STR_PAD_LEFT);
    }

    public function rates(string $base, string $buyMargin, string $sellMargin): array
    {
        $calc = app(TransferQuoteCalculator::class);
        try {
            $baseRate = $calc->rate($base);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['base' => 'Ingresa una base positiva con hasta seis decimales.']);
        }
        $margins = [];
        foreach (['buy_margin' => $buyMargin, 'sell_margin' => $sellMargin] as $key => $value) {
            Validator::make([$key => $value], [$key => ['required', 'regex:/\A[0-9]{1,3}(?:\.[0-9]{1,6})?\z/']])->validate();
            [$whole,$fraction] = array_pad(explode('.', $value, 2), 2, '');
            $margins[$key] = (int) $whole * TransferQuoteCalculator::SCALE + (int) str_pad($fraction, 6, '0');
        }
        $buy = $baseRate - $margins['buy_margin'];
        $sell = $baseRate + $margins['sell_margin'];
        if ($buy < 1) {
            throw ValidationException::withMessages(['buy_margin' => 'El descuento de compra debe ser menor que la base.']);
        }
        if ($sell > 999999999) {
            throw ValidationException::withMessages(['sell_margin' => 'La venta supera el límite técnico de 999,999999 Bs por real.']);
        }

        return ['base' => $baseRate, 'buy' => $buy, 'sell' => $sell, ...$margins];
    }

    public function preview(string $base, string $buyMargin, string $sellMargin, string $amount): array
    {
        $r = $this->rates($base, $buyMargin, $sellMargin);
        $calc = app(TransferQuoteCalculator::class);

        return [...$r, 'out' => $calc->calculate('BO_BR', 'BRL', $amount, $r['base'], $r['sell'], $r['buy']),
            'in' => $calc->calculate('BR_BO', 'BRL', $amount, $r['base'], $r['sell'], $r['buy'])];
    }
}
