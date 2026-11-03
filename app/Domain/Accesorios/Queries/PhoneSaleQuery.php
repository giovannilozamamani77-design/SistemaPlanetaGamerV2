<?php

namespace App\Domain\Accesorios\Queries;

use App\Domain\Accesorios\Actions\ReservePhoneAction;
use App\Domain\Accesorios\Calculators\PhoneSaleCalculator;
use App\Domain\Accesorios\Calculators\PhoneWarrantyCalculator;
use App\Domain\Accesorios\Data\PhoneSaleInput;
use App\Domain\Accesorios\Models\PhoneImei;
use App\Domain\Accesorios\Models\PhoneReservation;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PhoneSaleQuery
{
    public function catalogue(User $u, string $search = ''): LengthAwarePaginator
    {
        app(AccessorySaleQuery::class)->authorize($u);
        app(AccessorySaleQuery::class)->authorize($u, 'viewAny');
        $q = PhoneUnit::availableForSale()->with(['variant.model.brand', 'imeis']);
        if (trim($search) !== '') {
            app(PhoneInventoryQuery::class)->search($q, mb_substr(trim($search), 0, 80));
        }

        return app(PhoneInventoryQuery::class)->groupForDisplay($q)->orderByDesc('phone_units.id')->paginate(12, ['*'], 'phonesPage')->through(fn (PhoneUnit $p) => ['id' => $p->id, 'stock' => (int) $p->group_stock, 'name' => $p->variant->model->brand->name.' '.$p->variant->model->name, 'variant' => $p->variant->ram_gb.'/'.$p->variant->storage_gb.' GB', 'condition' => $p->condition === 'NEW' ? 'Nuevo' : 'Usado', 'color' => $p->color, 'imeis' => $p->imeis->pluck('imei')->all(), 'price_cents' => $p->sale_price_cents, 'image_url' => $p->image_path ? route('accesorios.telefono-imagen', ['phone' => $p->id]) : null, 'sale_allowed' => $p->sale_price_cents >= $p->historical_cost_cents]);
    }

    public function byImei(User $u, string $imei): int
    {
        app(AccessorySaleQuery::class)->authorize($u);
        Validator::make(['imei' => $imei], ['imei' => ['required', 'string', 'regex:/\A[0-9]{15}\z/']])->validate();
        $id = PhoneImei::where('imei', $imei)->value('unit_id');
        if (! $id) {
            throw ValidationException::withMessages(['code' => 'No se encontró ese IMEI.']);
        }

        return (int) $id;
    }

    public function add(User $u, array $basket, int $unitId, string $cartKey): array
    {
        app(AccessorySaleQuery::class)->authorize($u, 'viewAny');
        $basket = PhoneSaleInput::basket(['phone_lines' => $basket]);
        foreach ($basket as $line) {
            if ($line['unit_id'] === $unitId) {
                $this->preview($u, ['phone_lines' => $basket, 'request_key' => $cartKey]);

                return $basket;
            }
        }
        if (count($basket) >= 25) {
            throw ValidationException::withMessages(['cart' => 'La venta admite hasta 25 productos distintos.']);
        }
        $r = app(ReservePhoneAction::class)->execute($u, $unitId, $cartKey);
        $basket[] = ['unit_id' => $unitId, 'reservation_id' => $r->id, 'expected_price_cents' => $r->price_cents];

        return PhoneSaleInput::basket(['phone_lines' => $basket]);
    }

    public function nextMatchingUnit(User $u, array $basket, int $unitId, string $cartKey): int
    {
        app(AccessorySaleQuery::class)->authorize($u, 'viewAny');
        $this->preview($u, ['phone_lines' => $basket, 'request_key' => $cartKey]);
        if (! collect($basket)->contains('unit_id', $unitId)) {
            throw ValidationException::withMessages(['cart' => 'El teléfono ya no está en tu carrito.']);
        }
        $unit = PhoneUnit::findOrFail($unitId);
        $query = PhoneUnit::availableForSale()->whereNotIn('id', array_column($basket, 'unit_id'));
        foreach (['variant_id', 'condition', 'color', 'sale_price_cents', 'historical_cost_cents'] as $field) {
            $query->where($field, $unit->{$field});
        }
        $lot = $unit->registration_snapshot['request_key'] ?? null;
        $query->where('registration_snapshot->request_key', $lot);
        $next = $query->orderBy('id')->value('id');
        if (! $next) {
            throw ValidationException::withMessages(['cart' => 'No hay más stock disponible de este teléfono.']);
        }

        return (int) $next;
    }

    public function preview(User $u, array $data, ?array $discountRules = null): array
    {
        $user = app(AccessorySaleQuery::class)->authorize($u);
        $input = PhoneSaleInput::basket($data);
        if (! $input) {
            return [];
        }
        app(AccessorySaleQuery::class)->authorize($u, 'viewAny');
        Validator::make($data, ['request_key' => ['required', 'uuid']])->validate();
        $units = PhoneUnit::with(['variant.model.brand', 'imeis'])->whereIn('id', array_column($input, 'unit_id'))->get()->keyBy('id');
        $reservations = PhoneReservation::whereIn('id', array_column($input, 'reservation_id'))->get()->keyBy('id');
        $lines = [];
        $at = CarbonImmutable::now();
        foreach ($input as $line) {
            $p = $units->get($line['unit_id']);
            if (! $p) {
                throw ValidationException::withMessages(['cart' => 'No se encontró el equipo reservado.']);
            }
            $out = app(PhoneSaleCalculator::class)->line($p, $reservations->get($line['reservation_id']), $line, $user->id, $data['request_key'], $at, false, $discountRules);
            $out['warranty'] = app(PhoneWarrantyCalculator::class)->calculate($p, $at);
            $lines[] = $out;
        }

        return $lines;
    }
}
