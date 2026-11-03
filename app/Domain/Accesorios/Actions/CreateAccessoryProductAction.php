<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Queries\AccessoryInventoryQuery;
use App\Domain\Accesorios\Rules\AccessoryName;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateAccessoryProductAction
{
    public function execute(User $actor, array $data): AccessoryProduct
    {
        app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');
        $data['code'] = is_string($data['code'] ?? null) ? trim($data['code']) : ($data['code'] ?? null);
        $v = Validator::make($data, ['code' => ['required', 'string', 'max:60', 'regex:/\A[A-Za-z0-9][A-Za-z0-9 ._\/-]*\z/'], 'name' => ['required', 'string', 'max:160', new AccessoryName], 'category' => ['nullable', 'string', 'max:100'], 'minimum_stock' => ['nullable', 'integer', 'min:0', 'max:100000'], 'description' => ['nullable', 'string', 'max:1000'], 'request_key' => ['required', 'uuid']])->validate();
        $values = ['category' => filled($v['category'] ?? null) ? trim($v['category']) : null, 'minimum_stock' => (int) ($v['minimum_stock'] ?? 0), 'code' => strtoupper(trim($v['code'])), 'name' => trim($v['name']), 'description' => filled($v['description'] ?? null) ? trim($v['description']) : null, 'sale_price_cents' => PricingInput::money($data['sale_price'] ?? '', 'sale_price')];
        if ($values['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Ingresa el nombre del accesorio.']);
        }
        $hash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $values, $hash): AccessoryProduct {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');
            if ($old = AccessoryProduct::where('request_key', $v['request_key'])->first()) {
                if ($old->created_by !== $u->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['code' => 'Este envío ya tiene otros datos. Abre nuevamente el formulario.']);
                }

                return $old;
            }
            if (AccessoryProduct::where('code', $values['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'Este código ya está registrado. Selecciona el accesorio existente.']);
            }

            return AccessoryProduct::create($values + ['quantity' => 0, 'stock_value_cents' => 0, 'revision' => 0, 'created_by' => $u->id, 'actor_name' => $u->name, 'request_key' => $v['request_key'], 'payload_hash' => $hash]);
        }, 3);
    }
}
