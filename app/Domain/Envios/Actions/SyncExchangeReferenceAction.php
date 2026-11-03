<?php

namespace App\Domain\Envios\Actions;

use App\Domain\Envios\Authorization\TransferAuthorization;
use App\Domain\Envios\Calculators\TransferQuoteCalculator;
use App\Domain\Envios\Models\ExchangePolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SyncExchangeReferenceAction
{
    public function execute(?User $actor = null): bool
    {
        if (! config('envios.enabled', false)) {
            return false;
        }
        if ($actor) {
            app(TransferAuthorization::class)->admin(User::findOrFail($actor->id));
        }
        if (! Schema::hasTable('exchange_policies')) {
            return false;
        }
        if (! $actor && ! ExchangePolicy::latest('id')->first()?->reference_enabled) {
            return false;
        }

        return (bool) Cache::lock('envios-reference-sync', 30)->get(function (): bool {
            $previous = DB::table('exchange_references')->latest('quoted_at')->first();
            if ($previous && CarbonImmutable::parse($previous->next_update_at)->isFuture()) {
                return false;
            }
            if (! Cache::add('brasil-reference-attempt', true, 60)) {
                return false;
            }
            $data = Http::acceptJson()->connectTimeout(3)->timeout(8)->get('https://open.er-api.com/v6/latest/BRL')->throw()->json();
            $rate = $data['rates']['BOB'] ?? null;
            $quoted = $data['time_last_update_unix'] ?? null;
            $next = $data['time_next_update_unix'] ?? null;
            if (($data['result'] ?? null) !== 'success' || ($data['base_code'] ?? null) !== 'BRL' || ! is_numeric($rate) || ! is_finite((float) $rate)
                || (float) $rate <= 0 || (float) $rate >= 1000 || ! is_int($quoted) || ! is_int($next) || $next <= $quoted
                || $quoted > now()->timestamp + 300 || $quoted < now()->subDays(2)->timestamp || $next <= now()->timestamp) {
                throw ValidationException::withMessages(['reference' => 'La API devolvió una referencia inválida o desactualizada. Se conserva la anterior sin aplicar tasas nuevas.']);
            }
            $scaled = app(TransferQuoteCalculator::class)->rate(number_format((float) $rate, 6, '.', ''));
            DB::table('exchange_references')->updateOrInsert(['provider' => 'ExchangeRate-API', 'quoted_at' => CarbonImmutable::createFromTimestampUTC($quoted)],
                ['rate' => $scaled, 'next_update_at' => CarbonImmutable::createFromTimestampUTC($next), 'fetched_at' => now()]);

            return true;
        });
    }
}
