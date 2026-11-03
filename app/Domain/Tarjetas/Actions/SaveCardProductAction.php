<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Data\CardInventoryInput;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveCardProductAction
{
    public function __construct(private CardInventoryAuthorization $authorization, private CardInventoryCommandRunner $commands) {}

    public function execute(User $actor, array $data, ?CardProduct $target = null): CardProduct
    {
        $ability = $target ? 'update' : 'create';
        $this->authorization->authorize($actor, $ability);
        $v = Validator::make($data, ['company' => ['required', Rule::in(array_keys(CardInventoryInput::COMPANIES))], 'face_value' => ['required'], 'purchase_price' => ['required'], 'normal_price' => ['required'], 'wholesale_price' => ['required'], 'is_active' => ['required', 'boolean'], 'wholesale_enabled' => ['sometimes', 'boolean'], 'expected_revision' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        $values = ['company' => $v['company'], 'face_value_cents' => CardInventoryInput::money($v['face_value'], 'face_value'), 'purchase_price_cents' => CardInventoryInput::money($v['purchase_price'], 'purchase_price'), 'normal_price_cents' => CardInventoryInput::money($v['normal_price'], 'normal_price'), 'wholesale_price_cents' => CardInventoryInput::money($v['wholesale_price'], 'wholesale_price'), 'is_active' => (bool) $v['is_active']];
        $values['wholesale_enabled'] = (bool) ($v['wholesale_enabled'] ?? ($target?->wholesale_enabled ?? ($values['face_value_cents'] === 1000)));
        $customWholesale = ! $target && $values['wholesale_enabled'] !== ($values['face_value_cents'] === 1000);
        if ($customWholesale) {
            $this->authorization->authorize($actor, 'update');
        }
        $payload = ['id' => $target?->id, 'values' => $values, 'expected_revision' => (int) $v['expected_revision']];
        $result = $this->commands->execute($actor, $ability, 'SAVE_PRODUCT', $v['request_key'], $payload, function (CardStore $store, User $user) use ($target, $values, $v, $customWholesale): array {
            if ($customWholesale) {
                $this->authorization->authorize($user, 'update');
            }
            $product = $target ? CardProduct::whereKey($target->id)->lockForUpdate()->firstOrFail() : null;
            if (($product?->revision ?? 0) !== (int) $v['expected_revision']) {
                throw ValidationException::withMessages(['expected_revision' => 'Los precios cambiaron. Vuelve a abrir el formulario para ver la versión vigente.']);
            }
            if ($product && ($product->company !== $values['company'] || $product->face_value_cents !== $values['face_value_cents'])) {
                throw ValidationException::withMessages(['face_value' => 'La empresa y el corte identifican este producto y no se pueden cambiar. Crea otro producto.']);
            }
            if (! $product && CardProduct::where('company', $values['company'])->where('face_value_cents', $values['face_value_cents'])->exists()) {
                throw ValidationException::withMessages(['face_value' => 'Ya existe ese corte para esta empresa. Edita sus precios en el producto existente.']);
            }
            if ($product && ! $values['is_active'] && CardStock::where('card_product_id', $product->id)->where('quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['is_active' => 'No se puede desactivar un producto que todavía tiene tarjetas en tienda.']);
            }
            $before = $product?->toArray();
            if ($product) {
                $product->update($values + ['revision' => $product->revision + 1]);
            } else {
                $product = CardProduct::create($values + ['created_by' => $user->id, 'revision' => 1]);
                CardStock::create(['card_store_id' => $store->id, 'card_product_id' => $product->id, 'quantity' => 0]);
            }

            return ['product_id' => $product->id, 'before' => $before, 'after' => $product->toArray()];
        });

        return CardProduct::findOrFail($result['product_id']);
    }
}
