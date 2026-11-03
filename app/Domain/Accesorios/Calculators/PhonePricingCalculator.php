<?php

namespace App\Domain\Accesorios\Calculators;

use App\Domain\Accesorios\Data\PricingInput;
use App\Support\Bolivianos;
use Illuminate\Validation\ValidationException;

class PhonePricingCalculator
{
    public function calculate(array $settings, array $input, ?array $reference): array
    {
        $historical = $input['condition'] === 'NEW'
            ? intdiv($input['purchase_amount_cents'] * $input['purchase_rate_micros'] + 500000, PricingInput::SCALE)
            : $input['purchase_amount_cents'];
        $historical += $input['expenses_cents'];
        if ($historical > PricingInput::MAX_CENTS) {
            throw ValidationException::withMessages(['purchase_cost' => 'El costo excede el importe admitido.']);
        }
        $data = [
            'kind' => $input['condition'] === 'NEW' ? 'NEW_PHONE' : 'USED_PHONE',
            'historical_cost' => Bolivianos::format($historical),
            'replacement_usd' => Bolivianos::format($input['purchase_amount_cents']),
            'replacement_expenses' => Bolivianos::format($input['expenses_cents']),
            'manual_price' => $input['sale_price_cents'] !== null ? Bolivianos::format($input['sale_price_cents']) : null,
        ];
        if ($input['price_mode'] === 'MANUAL') {
            $data['price_mode'] = 'MANUAL';
        }
        try {
            $quote = app(PricingCalculator::class)->calculate($settings, $data, $reference);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['sale_price' => collect($e->errors())->flatten()->first()]);
        }

        return ['historical_cost_cents' => $historical, 'pricing' => $quote];
    }
}
