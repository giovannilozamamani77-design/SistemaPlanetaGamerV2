<?php

namespace App\Domain\Envios\Actions;

use App\Domain\Envios\Authorization\TransferAuthorization;
use App\Domain\Envios\Calculators\TransferQuoteCalculator;
use App\Domain\Envios\Models\ExchangePolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfigureExchangePolicyAction
{
    public function execute(User $actor, array $data): ExchangePolicy
    {
        $v = Validator::make($data, ['base' => ['required', 'string'], 'sell' => ['required', 'string'], 'buy' => ['required', 'string'],
            'cost_source' => ['required', 'string', 'min:3', 'max:500'], 'cost_confirmed' => ['accepted'], 'valid_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'reference_enabled' => ['required', 'boolean'], 'enabled' => ['required', 'boolean'], 'request_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $v): ExchangePolicy {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(TransferAuthorization::class)->admin($user);
            $hash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
            if ($prior = ExchangePolicy::where('request_key', $v['request_key'])->first()) {
                if ($prior->created_by !== $user->id || $prior->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['cost_source' => 'La solicitud ya fue utilizada con otros datos.']);
                }

                return $prior;
            }
            $calc = app(TransferQuoteCalculator::class);
            $base = $calc->rate($v['base']);
            $sell = $calc->rate($v['sell']);
            $buy = $calc->rate($v['buy']);
            if ($buy > $base || $base > $sell) {
                throw ValidationException::withMessages(['base' => 'Debe cumplirse compra ≤ base confirmada ≤ venta.']);
            }

            return ExchangePolicy::create(['created_by' => $user->id, 'base_rate' => $base, 'sell_rate' => $sell, 'buy_rate' => $buy,
                'cost_source' => $v['cost_source'], 'valid_minutes' => $v['valid_minutes'], 'reference_enabled' => $v['reference_enabled'], 'enabled' => $v['enabled'],
                'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
        });
    }
}
