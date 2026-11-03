<?php

namespace App\Domain\Accesorios\Queries;

use App\Domain\Accesorios\Calculators\AverageCostCalculator;
use App\Domain\Accesorios\Calculators\PricingCalculator;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryCategory;
use App\Domain\Accesorios\Models\AccessoryProduct;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Rules\AccessoryName;
use App\Infrastructure\Accesorios\ProductImageStorage;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccessoryRegistrationQuery
{
    public function settings(User $u): array
    {
        app(AccessoryInventoryQuery::class)->authorize($u, 'manage');

        return PricingSettings::where('code', 'GENERAL')->first()?->toArray() ?? PricingInput::defaults();
    }

    public function defaults(User $u): array
    {
        return ['code' => '', 'name' => '', 'category_id' => null, 'description' => '', 'unit_cost' => '', 'sale_price' => '', 'quantity' => 0, 'minimum_stock' => 0, 'image' => null, 'camera_image' => null, 'expected_pricing_revision' => $this->settings($u)['revision']];
    }

    public function categories(User $u, bool $onlyQuantity = true): Builder
    {
        app(AccessoryInventoryQuery::class)->authorize($u, 'manage');

        return AccessoryCategory::query()->when($onlyQuantity, fn ($q) => $q->where('tracking', 'QUANTITY')->where('is_active', true))->orderBy('name');
    }

    public function quote(User $u, mixed $cost, mixed $price = null): array
    {
        $s = $this->settings($u);
        $costCents = PricingInput::money($cost, 'unit_cost');
        $suggested = null;
        if ($s['rules']['ACCESSORY']['mode'] === 'PERCENT') {
            $suggested = app(PricingCalculator::class)->calculate($s, ['kind' => 'ACCESSORY', 'historical_cost' => Bolivianos::format($costCents)])['price_cents'];
        }
        $result = null;
        if (filled($price)) {
            $result = app(PricingCalculator::class)->calculate($s, ['kind' => 'ACCESSORY', 'price_mode' => 'MANUAL', 'historical_cost' => Bolivianos::format($costCents), 'manual_price' => $price]);
        }

        return ['cost_cents' => $costCents, 'suggested_price_cents' => $suggested, 'price_cents' => $result['price_cents'] ?? null, 'profit_cents' => $result['historic_profit_cents'] ?? null, 'markup_bps' => $result['markup_bps'] ?? null, 'settings_revision' => $s['revision'], 'configured_markup_bps' => $s['rules']['ACCESSORY']['markup_bps'], 'configured_mode' => $s['rules']['ACCESSORY']['mode']];
    }

    public function input(User $u, array $data, mixed $image = null): array
    {
        app(AccessoryInventoryQuery::class)->authorize($u, 'manage');
        $data['code'] = is_string($data['code'] ?? null) ? trim($data['code']) : ($data['code'] ?? null);
        $v = Validator::make($data, ['code' => ['required', 'string', 'max:60', 'regex:/\A[A-Za-z0-9][A-Za-z0-9 ._\/-]*\z/'], 'name' => ['required', 'string', 'max:160', new AccessoryName], 'category_id' => ['required', 'integer', 'exists:accessory_categories,id'], 'description' => ['nullable', 'string', 'max:1000'], 'quantity' => ['required', 'integer', 'min:0', 'max:100000'], 'minimum_stock' => ['nullable', 'integer', 'min:0', 'max:100000'], 'expected_pricing_revision' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        $name = trim($v['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Ingresa el nombre del accesorio.']);
        }
        $meta = app(ProductImageStorage::class)->inspect($image);

        return ['code' => strtoupper($v['code']), 'name' => $name, 'category_id' => (int) $v['category_id'], 'description' => filled($v['description'] ?? null) ? trim($v['description']) : null, 'quantity' => (int) $v['quantity'], 'minimum_stock' => (int) ($v['minimum_stock'] ?? 0), 'unit_cost_cents' => PricingInput::money($data['unit_cost'] ?? '', 'unit_cost'), 'sale_price_cents' => PricingInput::money($data['sale_price'] ?? '', 'sale_price'), 'expected_pricing_revision' => (int) $v['expected_pricing_revision'], 'request_key' => strtolower($v['request_key']), 'image_hash' => $meta['hash'] ?? null];
    }

    public function prepare(User $u, array $input, bool $checkCode = true): array
    {
        $s = $this->settings($u);
        $category = AccessoryCategory::findOrFail($input['category_id']);
        if (! $category->is_active || $category->tracking !== 'QUANTITY') {
            throw ValidationException::withMessages(['category_id' => 'Elige una categoría de accesorios por cantidad. Los teléfonos se registrarán por IMEI.']);
        }
        if ($s['revision'] !== $input['expected_pricing_revision']) {
            throw ValidationException::withMessages(['sale_price' => 'La configuración de precios cambió. Recalcula y revisa nuevamente.']);
        }
        if ($checkCode && AccessoryProduct::where('code', $input['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Este código ya existe. Usa Registrar entrada sobre el accesorio existente.']);
        }
        $quote = $this->quote($u, Bolivianos::format($input['unit_cost_cents']), Bolivianos::format($input['sale_price_cents']));
        $total = $input['quantity'] > 0 ? app(AverageCostCalculator::class)->receive(0, 0, $input['quantity'], $input['unit_cost_cents'])['entry_cents'] : 0;

        return ['values' => $input, 'category_name' => $category->name, 'total_cost_cents' => $total, 'profit_cents' => $quote['profit_cents'], 'markup_bps' => $quote['markup_bps'], 'suggested_price_cents' => $quote['suggested_price_cents'], 'pricing_revision' => $s['revision'], 'price_source' => $quote['suggested_price_cents'] === $input['sale_price_cents'] ? 'PERCENT' : 'MANUAL'];
    }

    public function review(User $u, array $data, mixed $image = null): array
    {
        return $this->prepare($u, $this->input($u, $data, $image));
    }
}
