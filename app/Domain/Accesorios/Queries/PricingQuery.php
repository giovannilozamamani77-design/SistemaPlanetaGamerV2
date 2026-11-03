<?php

namespace App\Domain\Accesorios\Queries;

use App\Domain\Accesorios\Calculators\PricingCalculator;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\ExchangeQuote;
use App\Domain\Accesorios\Models\PricingChange;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PricingQuery
{
    public function authorize(User $actor): User
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('configure', PricingSettings::class);

        return $user;
    }

    public function settings(User $actor): array
    {
        $this->authorize($actor);

        return PricingSettings::where('code', 'GENERAL')->first()?->toArray() ?? PricingInput::defaults();
    }

    public function latestQuote(): ?ExchangeQuote
    {
        return ExchangeQuote::orderByDesc('source_at')->orderByDesc('id')->first();
    }

    public function history(User $actor): Builder
    {
        $this->authorize($actor);

        return PricingChange::query();
    }

    public function form(User $actor): array
    {
        $s = $this->settings($actor);
        $form = ['new_phone_auto_update' => (bool) ($s['rules']['NEW_PHONE']['auto_update'] ?? false), 'exchange_mode' => $s['exchange_mode'], 'physical_rate' => PricingInput::rateText($s['physical_rate_micros']), 'adjustment' => PricingInput::rateText($s['adjustment_micros']), 'auto_refresh' => $s['auto_refresh'], 'expected_revision' => $s['revision']];
        foreach (PricingInput::PREFIXES as $kind => $prefix) {
            $form[$prefix.'_mode'] = $s['rules'][$kind]['mode'];
            $form[$prefix.'_percent'] = Bolivianos::formatCompact($s['rules'][$kind]['markup_bps']);
        }

        return $form;
    }

    public function dollarPreview(User $actor, mixed $adjustment): array
    {
        $settings = $this->settings($actor);
        $quote = $this->latestQuote();
        $preview = $settings;
        $rate = null;
        $error = null;
        try {
            if ($adjustment !== null) {
                $preview['adjustment_micros'] = PricingInput::rate($adjustment, 'adjustment', true);
            }
            $rate = app(PricingCalculator::class)->exchange($preview, $quote?->toArray())['effective_rate_micros'];
        } catch (ValidationException $e) {
            $error = $quote ? collect($e->errors())->flatten()->first() : null;
        }

        return ['settings' => $settings, 'quote' => $quote, 'effectiveRate' => $rate,
            'dollarChanged' => $preview['adjustment_micros'] !== $settings['adjustment_micros'], 'dollarError' => $error];
    }

    public function simulate(User $actor, array $data): array
    {
        $s = $this->settings($actor);

        $quote = $this->latestQuote()?->toArray();
        if (($data['kind'] ?? null) === 'NEW_PHONE' && array_key_exists('purchase_usd', $data)) {
            $usd = PricingInput::money($data['purchase_usd'], 'purchase_usd');
            $expenses = PricingInput::money($data['replacement_expenses'] ?? '0', 'replacement_expenses', true);
            $exchange = app(PricingCalculator::class)->exchange($s, $quote);
            $cost = intdiv($usd * $exchange['effective_rate_micros'] + 500000, PricingInput::SCALE) + $expenses;
            if ($cost > PricingInput::MAX_CENTS) {
                throw ValidationException::withMessages(['purchase_usd' => 'El costo convertido excede el importe permitido.']);
            }
            $compare = Validator::make($data, ['compare_original_cost' => ['sometimes', 'boolean']])->validate();
            if (! ($compare['compare_original_cost'] ?? false)) {
                $data['historical_cost'] = Bolivianos::format($cost);
            }
            $data['replacement_usd'] = Bolivianos::format($usd);
        }

        return app(PricingCalculator::class)->calculate($s, $data, $quote);
    }
}
