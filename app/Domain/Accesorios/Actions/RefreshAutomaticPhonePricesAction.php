<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Calculators\PricingCalculator;
use App\Domain\Accesorios\Models\PhoneReservation;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\PricingQuery;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Support\Facades\DB;

class RefreshAutomaticPhonePricesAction
{
    public function execute(?User $actor = null): int
    {
        if ($actor) {
            app(PricingQuery::class)->authorize($actor);
        }

        return DB::transaction(function () use ($actor): int {
            $settings = PricingSettings::where('code', 'GENERAL')->lockForUpdate()->first();
            if (! $settings || ! ($settings->rules['NEW_PHONE']['auto_update'] ?? false) || $settings->rules['NEW_PHONE']['mode'] !== 'PERCENT') {
                return 0;
            }
            $quote = app(PricingQuery::class)->latestQuote();
            if (! $quote || $quote->source_at->timezone('America/La_Paz')->toDateString() !== now('America/La_Paz')->toDateString()) {
                return 0;
            }
            $count = 0;
            PhoneUnit::where('condition', 'NEW')->availableForSale()->orderBy('id')->chunkById(100, function ($units) use ($settings, $quote, $actor, &$count): void {
                foreach ($units as $candidate) {
                    $unit = PhoneUnit::whereKey($candidate->id)->lockForUpdate()->first();
                    $reservation = $unit->status === 'RESERVED' ? PhoneReservation::whereKey($unit->reservation_id)->lockForUpdate()->first() : null;
                    $available = $unit->status === 'AVAILABLE' || ($unit->status === 'RESERVED' && $reservation?->status === 'ACTIVE' && ! $reservation->expires_at->isFuture());
                    if (! $available || ($unit->registration_snapshot['price_mode'] ?? 'MANUAL') !== 'CONFIGURED' || ($unit->pricing_snapshot['price_mode'] ?? 'MANUAL') !== 'PERCENT') {
                        continue;
                    }
                    $price = app(PricingCalculator::class)->calculate($settings->toArray(), [
                        'kind' => 'NEW_PHONE', 'price_mode' => 'PERCENT',
                        'historical_cost' => Bolivianos::format($unit->historical_cost_cents),
                        'replacement_usd' => Bolivianos::format($unit->purchase_amount_cents),
                        'replacement_expenses' => Bolivianos::format($unit->expenses_cents),
                    ], $quote?->toArray());
                    if ($price['price_cents'] === $unit->sale_price_cents) {
                        continue;
                    }
                    DB::table('phone_price_changes')->insert([
                        'phone_unit_id' => $unit->id, 'quote_id' => $price['quote_id'],
                        'settings_revision' => $settings->revision, 'previous_price_cents' => $unit->sale_price_cents,
                        'price_cents' => $price['price_cents'], 'pricing_snapshot' => json_encode($price, JSON_THROW_ON_ERROR),
                        'user_id' => $actor?->id, 'created_at' => now(),
                    ]);
                    $unit->update(['sale_price_cents' => $price['price_cents'], 'pricing_snapshot' => $price]);
                    $count++;
                }
            });

            return $count;
        }, 3);
    }
}
