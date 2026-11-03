<?php

namespace App\Domain\Accesorios\Calculators;

use App\Domain\Accesorios\Data\PricingInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PricingCalculator
{
    public function exchange(array $settings, ?array $quote): array
    {
        if (! $quote) {
            throw ValidationException::withMessages(['replacement_usd' => 'No hay referencia guardada. Actualiza el valor de la API.']);
        }
        $rate = $quote['rate_micros'] + $settings['adjustment_micros'];
        if ($rate <= 0 || $rate > 1000 * PricingInput::SCALE) {
            throw ValidationException::withMessages(['replacement_usd' => 'El cambio de referencia más ajuste supera el límite permitido.']);
        }

        return ['effective_rate_micros' => $rate, 'quote_id' => $quote['id'], 'reference_rate_micros' => $quote['rate_micros'], 'reference_at' => (string) $quote['source_at'], 'rate_source' => $quote['provider']];
    }

    public function calculate(array $settings, array $data, ?array $quote = null): array
    {
        Validator::make($data, ['kind' => ['required', Rule::in(array_keys(PricingInput::KINDS))], 'price_mode' => ['nullable', Rule::in(['MANUAL', 'PERCENT'])]])->validate();
        $historical = PricingInput::money($data['historical_cost'] ?? '', 'historical_cost');
        $base = $historical;
        $replacement = null;
        $exchange = ['effective_rate_micros' => null, 'quote_id' => null, 'reference_rate_micros' => null, 'reference_at' => null, 'rate_source' => null];
        if ($data['kind'] === 'NEW_PHONE') {
            $exchange = $this->exchange($settings, $quote);
            $usd = PricingInput::money($data['replacement_usd'] ?? '', 'replacement_usd');
            $expenses = PricingInput::money($data['replacement_expenses'] ?? '0', 'replacement_expenses', true);
            $replacement = intdiv($usd * $exchange['effective_rate_micros'] + intdiv(PricingInput::SCALE, 2), PricingInput::SCALE) + $expenses;
            $base = max($historical, $replacement);
        }
        $rule = $settings['rules'][$data['kind']];
        $mode = $data['price_mode'] ?? $rule['mode'];
        $price = $mode === 'MANUAL' ? PricingInput::money($data['manual_price'] ?? '', 'manual_price') : intdiv($base * (10000 + $rule['markup_bps']) + 5000, 10000);
        if ($price > PricingInput::MAX_CENTS || $base > PricingInput::MAX_CENTS) {
            throw ValidationException::withMessages(['manual_price' => 'La sugerencia excede el límite de importes admitido.']);
        }
        if ($price < $historical) {
            throw ValidationException::withMessages(['manual_price' => 'El precio no puede quedar por debajo del costo histórico real.']);
        }
        $gain = $price - $base;

        return ['kind' => $data['kind'], 'price_mode' => $mode, 'settings_revision' => $settings['revision'], 'historical_cents' => $historical, 'replacement_cents' => $replacement, 'base_cents' => $base, 'price_cents' => $price, 'historic_profit_cents' => $price - $historical, 'base_profit_cents' => $gain, 'below_replacement' => $replacement !== null && $price < $replacement, 'markup_bps' => $this->ratio($gain, $base), 'margin_bps' => $this->ratio($gain, $price)] + $exchange;
    }

    private function ratio(int $gain, int $denominator): int
    {
        return ($gain < 0 ? -1 : 1) * intdiv(abs($gain) * 10000 + intdiv($denominator, 2), $denominator);
    }
}
