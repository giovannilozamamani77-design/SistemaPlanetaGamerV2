<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Domain\Tarjetas\Services\CardStockEntryRecorder;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReceiveEntelCardsAction
{
    public function __construct(private CardInventoryAuthorization $authorization, private CardInventoryCommandRunner $commands, private CardStockEntryRecorder $entries) {}

    public function execute(User $actor, array $data): CardInventoryMovement
    {
        $this->authorization->authorize($actor, 'receive');
        $v = Validator::make($data, ['product_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1', 'max:1000000'], 'unit_cost' => ['nullable'], 'reference' => ['nullable', 'string', 'max:200'], 'request_key' => ['required', 'uuid']])->validate();
        $payload = ['product_id' => (int) $v['product_id'], 'quantity' => (int) $v['quantity'], 'unit_cost_cents' => filled($v['unit_cost'] ?? null) ? CardInventoryInput::money($v['unit_cost'], 'unit_cost') : null, 'reference' => trim($v['reference'] ?? '')];
        $result = $this->commands->execute($actor, 'receive', 'ADMIN_DELIVERY', $v['request_key'], $payload, function (CardStore $store, User $user) use ($payload): array {
            $product = CardProduct::whereKey($payload['product_id'])->lockForUpdate()->first();
            if (! $product) {
                throw ValidationException::withMessages(['product_id' => 'El producto ya no está disponible. Vuelve a abrir el formulario y selecciona uno del catálogo.']);
            }
            $movement = $this->entries->execute($store, $user, $product, 'ADMIN_DELIVERY', $payload['quantity'], $payload['unit_cost_cents'], $payload['reference']);

            return ['movement_id' => $movement->id];
        });

        return CardInventoryMovement::findOrFail($result['movement_id']);
    }
}
