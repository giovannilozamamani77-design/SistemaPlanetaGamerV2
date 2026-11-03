<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\EntelDigitalAccount;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveEntelRechargeRatesAction
{
    public function execute(User $actor, array $data): void
    {
        Gate::forUser($actor->fresh())->authorize('record', EntelDigitalAccount::class);
        $v = Validator::make($data, ['credit' => ['required'], 'packages' => ['required'], 'request_key' => ['required', 'uuid']])->validate();
        $payload = ['credit' => CardInventoryInput::money($v['credit'], 'credit', true), 'packages' => CardInventoryInput::money($v['packages'], 'packages', true)];
        foreach ($payload as $field => $bps) {
            if ($bps > 10000) {
                throw ValidationException::withMessages([$field => 'Máximo 100 %.']);
            }
        }
        app(CardInventoryCommandRunner::class)->execute($actor, 'update', 'ENTEL_RATES', $v['request_key'], $payload, function (CardStore $store, User $user) use ($payload) {
            Gate::forUser($user->fresh())->authorize('record', EntelDigitalAccount::class);
            $before = ['credit' => $store->entel_credit_bonus_bps, 'packages' => $store->entel_packages_bonus_bps];
            $store->update(['entel_credit_bonus_bps' => $payload['credit'], 'entel_packages_bonus_bps' => $payload['packages'], 'revision' => $store->revision + 1]);

            return ['before' => $before, 'after' => $payload];
        });
    }
}
