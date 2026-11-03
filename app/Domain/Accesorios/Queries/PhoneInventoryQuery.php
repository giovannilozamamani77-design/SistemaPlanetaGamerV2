<?php

namespace App\Domain\Accesorios\Queries;

use App\Domain\Accesorios\Calculators\PhonePricingCalculator;
use App\Domain\Accesorios\Calculators\PricingCalculator;
use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Accesorios\Models\PhoneVariant;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Infrastructure\Accesorios\ProductImageStorage;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PhoneInventoryQuery
{
    public function authorize(User $u, string $ability = 'viewAny'): User
    {
        return app(AccessoryInventoryQuery::class)->authorize($u, $ability);
    }

    public function units(User $u): Builder
    {
        $this->authorize($u);

        return PhoneUnit::with(['variant.model.brand', 'imeis', 'reservation']);
    }

    public function groupForDisplay(Builder $query): Builder
    {
        $effectiveStatus = "CASE WHEN phone_units.status = 'RESERVED' AND EXISTS (SELECT 1 FROM phone_reservations WHERE phone_reservations.id = phone_units.reservation_id AND phone_reservations.status = 'ACTIVE' AND phone_reservations.expires_at <= ?) THEN 'AVAILABLE' ELSE phone_units.status END";
        $groups = (clone $query)->reorder()->selectRaw('MAX(phone_units.id) AS representative_id, COUNT(*) AS group_stock')
            ->groupBy(['phone_units.variant_id', 'phone_units.condition', 'phone_units.color', 'phone_units.sale_price_cents', 'phone_units.historical_cost_cents', 'phone_units.registration_snapshot->request_key'])
            ->groupByRaw($effectiveStatus, [now()]);

        return $query->joinSub($groups->toBase(), 'phone_stock_groups', fn ($join) => $join->on('phone_units.id', '=', 'phone_stock_groups.representative_id'))
            ->select('phone_units.*', 'phone_stock_groups.group_stock');
    }

    public function effectiveStatus(PhoneUnit $phone): string
    {
        if ($phone->status === 'RESERVED' && $phone->reservation?->status === 'ACTIVE' && ! $phone->reservation->expires_at->isFuture()) {
            return 'AVAILABLE';
        }

        return $phone->status;
    }

    public function availableStock(PhoneUnit $phone): int
    {
        return $this->effectiveStatus($phone) === 'AVAILABLE' ? (int) ($phone->group_stock ?? 1) : 0;
    }

    public function search(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $q) use ($search): void {
            $q->where('color', 'like', '%'.$search.'%')->orWhereHas('imeis', fn (Builder $x) => $x->where('imei', 'like', '%'.$search.'%'))->orWhereHas('variant.model', fn (Builder $x) => $x->where('name', 'like', '%'.$search.'%')->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', '%'.$search.'%')));
            if (preg_match('/\A[0-9]{1,4}\z/', $search)) {
                $q->orWhereHas('variant', fn (Builder $x) => $x->where('ram_gb', (int) $search)->orWhere('storage_gb', (int) $search));
            }
            $amount = str_replace(',', '.', $search);
            if (preg_match('/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/', $amount)) {
                $q->orWhere('sale_price_cents', Bolivianos::cents($amount));
            }
        });
    }

    public function summary(User $u): array
    {
        $this->authorize($u);
        $counts = PhoneUnit::availableForSale()->selectRaw('condition, count(*) as total')->groupBy('condition')->pluck('total', 'condition');

        return ['total' => (int) $counts->sum(), 'new' => (int) ($counts['NEW'] ?? 0), 'used' => (int) ($counts['USED'] ?? 0)];
    }

    public function variants(User $u): Builder
    {
        $this->authorize($u);

        return PhoneVariant::with('model.brand')->withCount('availableUnits');
    }

    public function defaults(User $u): array
    {
        $this->authorize($u, 'manage');

        return ['condition' => 'USED', 'price_mode' => 'MANUAL', 'expenses' => '0', 'quantity' => 1, 'expected_pricing_revision' => $this->settings()['revision']];
    }

    public function settings(): array
    {
        return PricingSettings::where('code', 'GENERAL')->first()?->toArray() ?? PricingInput::defaults();
    }

    public function operatingRate(User $actor): array
    {
        $this->authorize($actor, 'manage');
        $settings = $this->settings();
        $quote = app(PricingQuery::class)->latestQuote();
        if (! $quote || $quote->source_at->timezone('America/La_Paz')->toDateString() !== now('America/La_Paz')->toDateString()) {
            throw ValidationException::withMessages(['purchase_cost' => 'Actualiza la referencia de hoy en Configuración de precio de venta para continuar con el cambio global.']);
        }

        return app(PricingCalculator::class)->exchange($settings, $quote?->toArray());
    }

    public function registrationPreview(User $actor, array $data): array
    {
        $this->authorize($actor, 'manage');
        $rate = null;
        try {
            $rate = ($data['condition'] ?? 'USED') === 'NEW' ? $this->operatingRate($actor)['effective_rate_micros'] : null;
            $amount = filled($data['purchase_cost'] ?? null) ? PricingInput::money($data['purchase_cost'], 'purchase_cost') : null;
            $cost = $amount === null ? null : ($rate === null ? $amount : intdiv($amount * $rate + 500000, PricingInput::SCALE));

            return ['rate' => $rate === null ? null : PricingInput::rateText($rate), 'cost' => $cost === null ? null : Bolivianos::formatCompact($cost), 'error' => null];
        } catch (ValidationException $e) {
            return ['rate' => $rate === null ? null : PricingInput::rateText($rate), 'cost' => null, 'error' => collect($e->errors())->flatten()->first()];
        }
    }

    public function normalize(string $s): string
    {
        return Str::lower(Str::ascii(Str::squish($s)));
    }

    public function input(User $u, array $d, mixed $image = null, ?int $recordedRate = null): array
    {
        $this->authorize($u, 'manage');
        foreach (['brand', 'model', 'color', 'imei1', 'imei2', 'physical_state', 'included_accessories', 'origin'] as $key) {
            if (is_string($d[$key] ?? null)) {
                $d[$key] = Str::squish($d[$key]);
            }
        }
        $v = Validator::make($d, ['brand' => ['required', 'string', 'max:80'], 'model' => ['required', 'string', 'max:100'], 'ram_gb' => ['required', 'integer', 'min:1', 'max:128'], 'storage_gb' => ['required', 'integer', 'min:1', 'max:8192'], 'condition' => ['required', Rule::in(['NEW', 'USED'])], 'color' => ['required', 'string', 'max:60'], 'quantity' => ['sometimes', 'integer', 'min:1', 'max:1000'], 'imei1' => ['nullable', 'string', 'regex:/\A[0-9]{15}\z/'], 'imei2' => ['nullable', 'string', 'regex:/\A[0-9]{15}\z/', 'different:imei1'], 'physical_state' => ['nullable', 'string', 'max:1000'], 'included_accessories' => ['nullable', 'string', 'max:500'], 'origin' => ['nullable', 'string', 'max:200'], 'price_mode' => ['required', Rule::in(['CONFIGURED', 'MANUAL'])], 'expected_pricing_revision' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        foreach (['imei1', 'imei2', 'physical_state', 'included_accessories', 'origin'] as $key) {
            $v[$key] = filled($v[$key] ?? null) ? $v[$key] : null;
        }
        $quantity = (int) ($v['quantity'] ?? 1);
        if ($quantity > 1 && ($v['imei1'] || $v['imei2'])) {
            throw ValidationException::withMessages(['quantity' => 'Para varias unidades deja los IMEI vacíos; se registran por equipo al vender.']);
        }
        if ($v['imei2'] && ! $v['imei1']) {
            throw ValidationException::withMessages(['imei1' => 'Ingresa el primer IMEI antes del segundo.']);
        }
        if ($quantity === 1) {
            unset($v['quantity']);
        } else {
            $v['quantity'] = $quantity;
        }
        $v['ram_gb'] = (int) $v['ram_gb'];
        $v['storage_gb'] = (int) $v['storage_gb'];
        $v['expected_pricing_revision'] = (int) $v['expected_pricing_revision'];
        $v['request_key'] = strtolower($v['request_key']);
        $v['purchase_amount_cents'] = PricingInput::money($d['purchase_cost'] ?? '', 'purchase_cost');
        $v['purchase_rate_micros'] = $v['condition'] === 'NEW' ? ($recordedRate ?? $this->operatingRate($u)['effective_rate_micros']) : null;
        $v['expenses_cents'] = PricingInput::money($d['expenses'] ?? '0', 'expenses', true);
        $v['sale_price_cents'] = $v['price_mode'] === 'MANUAL' ? PricingInput::money($d['sale_price'] ?? '', 'sale_price') : null;
        $v['image_hash'] = app(ProductImageStorage::class)->inspect($image)['hash'] ?? null;

        return $v;
    }

    public function prepare(array $v): array
    {
        $s = $this->settings();
        if ($s['revision'] !== $v['expected_pricing_revision']) {
            throw ValidationException::withMessages(['sale_price' => 'La configuración cambió. Revisa nuevamente.']);
        }
        $r = ['values' => $v] + app(PhonePricingCalculator::class)->calculate($s, $v, app(PricingQuery::class)->latestQuote()?->toArray());
        $r['fingerprint'] = hash('sha256', json_encode($r, JSON_THROW_ON_ERROR));

        return $r;
    }

    public function review(User $u, array $d, mixed $image = null, ?int $recordedRate = null): array
    {
        return $this->prepare($this->input($u, $d, $image));
    }
}
