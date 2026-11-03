<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class SaveCardWholesaleRulesAction
{
    public function __construct(private CardInventoryCommandRunner $commands, private CardInventoryAuthorization $authorization) {}

    public function execute(User $actor, array $data): void
    {
        $this->authorization->authorize($actor, 'update');
        $v = Validator::make($data, ['minimum' => ['required', 'integer', 'min:1', 'max:1000000'], 'request_key' => ['required', 'uuid']])->validate();
        $this->commands->execute($actor, 'update', 'WHOLESALE_RULES', $v['request_key'], ['minimum' => (int) $v['minimum']], function (CardStore $store) use ($v): array {
            $before = $store->wholesale_min_quantity;
            $store->update(['wholesale_min_quantity' => (int) $v['minimum']]);

            return ['before' => $before, 'after' => $store->wholesale_min_quantity];
        });
    }
}
