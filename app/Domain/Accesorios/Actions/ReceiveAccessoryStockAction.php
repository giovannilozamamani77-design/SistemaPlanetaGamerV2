<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Calculators\AverageCostCalculator;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\AccessoryStockEntry;
use App\Domain\Accesorios\Queries\AccessoryInventoryQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReceiveAccessoryStockAction
{
    public function execute(User $actor, array $data): AccessoryStockEntry
    {
        app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');
        $v = Validator::make($data, ['product_id' => ['required', 'integer', 'exists:accessory_products,id'], 'quantity' => ['required', 'integer', 'min:1', 'max:100000'], 'kind' => ['required', Rule::in(['INITIAL', 'PURCHASE'])], 'supplier' => ['nullable', 'string', 'max:160'], 'note' => ['nullable', 'string', 'max:500'], 'expected_revision' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        $values = ['product_id' => (int) $v['product_id'], 'quantity' => (int) $v['quantity'], 'kind' => $v['kind'], 'unit_cost_cents' => ($v['kind'] === 'INITIAL' && ! filled($data['unit_cost'] ?? null)) ? null : PricingInput::money($data['unit_cost'] ?? '', 'unit_cost'), 'supplier' => filled($v['supplier'] ?? null) ? trim($v['supplier']) : null, 'note' => filled($v['note'] ?? null) ? trim($v['note']) : null, 'previous_revision' => (int) $v['expected_revision']];
        $hash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $values, $hash): AccessoryStockEntry {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');
            $p = AccessoryProduct::whereKey($values['product_id'])->lockForUpdate()->firstOrFail();
            if ($old = AccessoryStockEntry::where('request_key', $v['request_key'])->first()) {
                if ($old->user_id !== $u->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['quantity' => 'Este envío ya se confirmó con otros datos. Abre nuevamente la entrada.']);
                }

                return $old;
            }
            if ($p->revision !== $values['previous_revision']) {
                throw ValidationException::withMessages(['quantity' => 'El inventario cambió. Vuelve a abrir la entrada para revisar el stock actual.']);
            }
            if ($values['kind'] === 'INITIAL' && $p->revision !== 0) {
                throw ValidationException::withMessages(['kind' => 'El inventario inicial solo se registra antes de la primera compra.']);
            }
            if ($p->cost_pending && ($values['kind'] !== 'INITIAL' || $values['unit_cost_cents'] !== null)) {
                throw ValidationException::withMessages(['unit_cost' => 'Completa el costo inicial antes de registrar otra entrada.']);
            }
            if ($values['unit_cost_cents'] === null && ! $p->cost_pending) {
                throw ValidationException::withMessages(['unit_cost' => 'Ingresa el costo por unidad.']);
            }
            $next = $p->cost_pending
                ? ['quantity' => $values['quantity'], 'value_cents' => 0, 'entry_cents' => null]
                : app(AverageCostCalculator::class)->receive($p->quantity, $p->stock_value_cents, $values['quantity'], $values['unit_cost_cents']);
            $event = AccessoryStockEntry::create($values + ['user_id' => $u->id, 'actor_name' => $u->name, 'total_cost_cents' => $next['entry_cents'], 'before_quantity' => $p->quantity, 'after_quantity' => $next['quantity'], 'before_value_cents' => $p->stock_value_cents, 'after_value_cents' => $next['value_cents'], 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            $p->update(['quantity' => $next['quantity'], 'stock_value_cents' => $next['value_cents'], 'revision' => $p->revision + 1]);

            return $event;
        }, 3);
    }
}
