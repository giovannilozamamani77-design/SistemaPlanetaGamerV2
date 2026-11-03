<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Models\ExchangeQuote;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\PricingQuery;
use App\Infrastructure\Accesorios\ParaleloReferenceClient;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RefreshPricingReferenceAction
{
    public function execute(?User $actor = null, bool $force = false, bool $displayOnly = false): ?ExchangeQuote
    {
        if ($actor) {
            app(PricingQuery::class)->authorize($actor);
        }
        if (! Schema::hasTable('accessory_pricing_settings')) {
            return null;
        }
        $settings = PricingSettings::where('code', 'GENERAL')->first();
        if (! $force && ! $displayOnly && ($settings && ! $settings->auto_refresh)) {
            return null;
        }

        $quote = Cache::lock('accessories:pricing-reference', 15)->get(function () use ($force, $displayOnly): ?ExchangeQuote {
            $last = app(PricingQuery::class)->latestQuote();
            if ($displayOnly && ! $force && Cache::has('accessories:pricing-reference:display-failed')) {
                throw ValidationException::withMessages(['reference' => 'No se pudo consultar el dólar. Vuelve a intentar en un minuto o usa el cambio manual.']);
            }
            if (! $force && $last && ($last->fetched_at->greaterThan(now()->subMinutes(30)) || Cache::has('accessories:pricing-reference:checked'))) {
                return $last;
            }
            try {
                $values = app(ParaleloReferenceClient::class)->fetch();
            } catch (ValidationException $e) {
                if ($displayOnly) {
                    Cache::put('accessories:pricing-reference:display-failed', true, now()->addMinute());
                }
                throw $e;
            }
            Cache::forget('accessories:pricing-reference:display-failed');
            Cache::put('accessories:pricing-reference:checked', true, now()->addMinutes(30));
            if ($last && $values['source_at']->lessThan($last->source_at)) {
                return $last;
            }

            return ExchangeQuote::firstOrCreate(['fingerprint' => $values['fingerprint']], $values + ['fetched_at' => now()]);
        });
        if ($quote && ! $displayOnly) {
            app(RefreshAutomaticPhonePricesAction::class)->execute($actor);
        }

        return $quote;
    }
}
