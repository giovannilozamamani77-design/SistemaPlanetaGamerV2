<?php

namespace App\Domain\Envios\Actions;

use App\Domain\Envios\Authorization\TransferAuthorization;
use App\Domain\Envios\Calculators\UsdRateCalculator;
use App\Domain\Envios\Models\UsdRateSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfigureUsdRatesAction
{
    public function execute(User $actor, array $data): UsdRateSetting
    {
        app(TransferAuthorization::class)->admin(User::findOrFail($actor->id));
        $v = Validator::make($data, ['buy' => ['required', 'string'], 'sell' => ['required', 'string'], 'reason' => ['required', 'string', 'min:3', 'max:500'],
            'expected_id' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        $prices = app(UsdRateCalculator::class)->prices($v['buy'], $v['sell']);

        return Cache::lock('usd-rate-configuration', 10)->block(3, function () use ($actor, $v, $prices): UsdRateSetting {
            return DB::transaction(function () use ($actor, $v, $prices): UsdRateSetting {
                $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                app(TransferAuthorization::class)->admin($user);
                $hash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
                if ($prior = UsdRateSetting::where('request_key', $v['request_key'])->first()) {
                    if ($prior->created_by !== $user->id || $prior->payload_hash !== $hash) {
                        throw ValidationException::withMessages(['reason' => 'Esta solicitud ya se utilizó con otros datos.']);
                    }

                    return $prior;
                }
                if ((int) UsdRateSetting::max('id') !== (int) $v['expected_id']) {
                    throw ValidationException::withMessages(['reason' => 'Otra persona actualizó las tasas. Recarga la página y revisa los valores antes de guardar.']);
                }
                $ref = DB::table('usd_rate_references')->latest('quoted_at')->first();

                return UsdRateSetting::create(['buy_rate' => $prices['buy'], 'sell_rate' => $prices['sell'], 'created_by' => $user->id, 'reason' => $v['reason'],
                    'reference_rate' => $ref?->rate, 'reference_quoted_at' => $ref?->quoted_at, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            });
        });
    }
}
