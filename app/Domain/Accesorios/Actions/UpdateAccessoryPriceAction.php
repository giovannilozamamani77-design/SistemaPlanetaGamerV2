<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Calculators\AverageCostCalculator;
use App\Domain\Accesorios\Calculators\PricingCalculator;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryPriceChange;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\AccessoryInventoryQuery;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateAccessoryPriceAction
{
    public function execute(User $actor, array $data): AccessoryPriceChange
    {
        app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');
        $v = Validator::make($data, ['product_id' => ['required', 'integer', 'exists:accessory_products,id'], 'price_mode' => ['required', Rule::in(['MANUAL', 'PERCENT'])], 'expected_revision' => ['required', 'integer', 'min:0'], 'expected_pricing_revision' => ['required_if:price_mode,PERCENT', 'nullable', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        $input = ['product_id' => (int) $v['product_id'], 'price_mode' => $v['price_mode'], 'expected_revision' => (int) $v['expected_revision'], 'expected_pricing_revision' => $v['price_mode'] === 'PERCENT' ? (int) $v['expected_pricing_revision'] : null, 'manual_cents' => $v['price_mode'] === 'MANUAL' ? PricingInput::money($data['manual_price'] ?? '', 'manual_price') : null];
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $input, $hash, $v): AccessoryPriceChange {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(AccessoryInventoryQuery::class)->authorize($actor, 'manage');
            $p = AccessoryProduct::whereKey($input['product_id'])->lockForUpdate()->firstOrFail();
            if ($old = AccessoryPriceChange::where('request_key', $v['request_key'])->first()) {
                if ($old->user_id !== $u->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['manual_price' => 'Este envío ya se registró con otros datos.']);
                }

                return $old;
            }
            if ($p->revision !== $input['expected_revision']) {
                throw ValidationException::withMessages(['manual_price' => 'El inventario cambió. Abre nuevamente el ajuste.']);
            }
            if ($p->quantity < 1) {
                throw ValidationException::withMessages(['manual_price' => 'Registra primero existencias con su costo.']);
            }
            $s = PricingSettings::where('code', 'GENERAL')->first()?->toArray() ?? PricingInput::defaults();
            if ($input['price_mode'] === 'PERCENT' && ($s['revision'] !== $input['expected_pricing_revision'] || $s['rules']['ACCESSORY']['mode'] !== 'PERCENT')) {
                throw ValidationException::withMessages(['price_mode' => 'Revisa el porcentaje en Configuración de precios y vuelve a abrir el ajuste.']);
            }
            $result = app(PricingCalculator::class)->calculate($s, ['kind' => 'ACCESSORY', 'price_mode' => $input['price_mode'], 'historical_cost' => Bolivianos::format(app(AverageCostCalculator::class)->unitCostCeiling($p->quantity, $p->stock_value_cents)), 'manual_price' => $input['manual_cents'] !== null ? Bolivianos::format($input['manual_cents']) : '']);
            $event = AccessoryPriceChange::create(['product_id' => $p->id, 'user_id' => $u->id, 'actor_name' => $u->name, 'price_mode' => $input['price_mode'], 'old_price_cents' => $p->sale_price_cents, 'new_price_cents' => $result['price_cents'], 'cost_basis_cents' => $result['historical_cents'], 'pricing_revision' => $result['settings_revision'], 'markup_bps' => $input['price_mode'] === 'PERCENT' ? $s['rules']['ACCESSORY']['markup_bps'] : $result['markup_bps'], 'previous_revision' => $p->revision, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            $p->update(['sale_price_cents' => $result['price_cents'], 'revision' => $p->revision + 1]);

            return $event;
        }, 3);
    }
}
