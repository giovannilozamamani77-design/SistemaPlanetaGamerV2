<?php

namespace App\Domain\Envios\Actions;

use App\Domain\Envios\Authorization\TransferAuthorization;
use App\Domain\Envios\Calculators\UsdRateCalculator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class SyncUsdReferenceAction
{
    public function execute(User $actor): bool
    {
        if (! config('envios.enabled', false)) {
            return false;
        }
        app(TransferAuthorization::class)->admin(User::findOrFail($actor->id));

        return (bool) Cache::lock('usd-reference-sync', 20)->get(function (): bool {
            $previous = DB::table('usd_rate_references')->latest('quoted_at')->first();
            if (($previous && CarbonImmutable::parse($previous->next_update_at)->isFuture()) || ! Cache::add('usd-reference-attempt', true, 60)) {
                return false;
            }
            $data = Http::acceptJson()->connectTimeout(3)->timeout(8)->get('https://open.er-api.com/v6/latest/USD')->throw()->json();
            $rate = $data['rates']['BOB'] ?? null;
            $quoted = $data['time_last_update_unix'] ?? null;
            $next = $data['time_next_update_unix'] ?? null;
            if (($data['result'] ?? null) !== 'success' || ($data['base_code'] ?? null) !== 'USD' || ! is_numeric($rate) || ! is_finite((float) $rate) || (float) $rate <= 0 || (float) $rate >= 1000
                || ! is_int($quoted) || ! is_int($next) || $next <= $quoted || $quoted > now()->timestamp + 300 || $quoted < now()->subDays(2)->timestamp || $next <= now()->timestamp) {
                throw ValidationException::withMessages(['reference' => 'La API devolvió datos inválidos o vencidos. Se conserva la última referencia y tus tasas.']);
            }
            $scaled = app(UsdRateCalculator::class)->prices(number_format((float) $rate, 6, '.', ''), number_format((float) $rate, 6, '.', ''))['buy'];
            DB::table('usd_rate_references')->updateOrInsert(['quoted_at' => CarbonImmutable::createFromTimestampUTC($quoted)],
                ['provider' => 'ExchangeRate-API', 'rate' => $scaled, 'next_update_at' => CarbonImmutable::createFromTimestampUTC($next), 'fetched_at' => now()]);

            return true;
        });
    }
}
