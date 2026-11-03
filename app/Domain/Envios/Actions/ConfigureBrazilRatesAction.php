<?php

namespace App\Domain\Envios\Actions;

use App\Domain\Envios\Authorization\TransferAuthorization;
use App\Domain\Envios\Calculators\BrazilRateCalculator;
use App\Domain\Envios\Models\ExchangePolicy;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfigureBrazilRatesAction
{
    public function execute(User $actor, array $data): ExchangePolicy
    {
        app(TransferAuthorization::class)->admin(User::findOrFail($actor->id));
        $v = Validator::make($data, ['base' => ['required', 'string'], 'buy_margin' => ['required', 'string'], 'sell_margin' => ['required', 'string'],
            'cost_source' => ['required', 'string', 'min:3', 'max:500'], 'cost_confirmed' => ['accepted'], 'valid_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'enabled' => ['required', 'boolean'], 'reference_enabled' => ['required', 'boolean'], 'expected_id' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        $calc = app(BrazilRateCalculator::class);
        $rates = $calc->rates($v['base'], $v['buy_margin'], $v['sell_margin']);
        $command = ['base' => $calc->input($rates['base']), 'buy' => $calc->input($rates['buy']), 'sell' => $calc->input($rates['sell']),
            'cost_source' => $v['cost_source'], 'cost_confirmed' => $v['cost_confirmed'], 'valid_minutes' => $v['valid_minutes'], 'enabled' => $v['enabled'], 'reference_enabled' => $v['reference_enabled'], 'request_key' => $v['request_key']];

        return Cache::lock('brasil-rate-configuration', 10)->block(3, function () use ($actor, $v, $command): ExchangePolicy {
            return DB::transaction(function () use ($actor, $v, $command): ExchangePolicy {
                $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                app(TransferAuthorization::class)->admin($user);
                if (! ExchangePolicy::where('request_key', $v['request_key'])->exists() && (int) ExchangePolicy::max('id') !== (int) $v['expected_id']) {
                    throw ValidationException::withMessages(['cost_source' => 'Las tasas cambiaron en otra sesión. Recarga y revisa antes de guardar.']);
                }

                return app(ConfigureExchangePolicyAction::class)->execute($user, $command);
            });
        });
    }
}
