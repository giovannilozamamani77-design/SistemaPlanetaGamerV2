<?php

namespace App\Domain\Accesorios\Calculators;

use App\Domain\Accesorios\Data\PhoneSaleInput;
use App\Domain\Accesorios\Models\PhoneReservation;
use App\Domain\Accesorios\Models\PhoneUnit;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class PhoneSaleCalculator
{
    public function line(PhoneUnit $p, ?PhoneReservation $r, array $input, int $userId, string $cartKey, CarbonImmutable $at, bool $requireImei = false, ?array $discountRules = null): array
    {
        if (! $r || $p->status !== 'RESERVED' || $p->reservation_id !== $r->id || $r->unit_id !== $p->id || $r->user_id !== $userId || ! hash_equals($r->cart_key, $cartKey) || $r->status !== 'ACTIVE' || $r->expires_at->lessThanOrEqualTo($at)) {
            throw ValidationException::withMessages(['cart' => 'La reserva del teléfono venció o ya no pertenece a esta venta. Retíralo y selecciona el IMEI otra vez.']);
        }
        if ($p->sale_price_cents !== $input['expected_price_cents'] || $r->price_cents !== $p->sale_price_cents) {
            throw ValidationException::withMessages(['cart' => 'El precio del teléfono cambió. Retíralo y agrégalo nuevamente.']);
        }
        if ($p->sale_price_cents < $p->historical_cost_cents) {
            throw ValidationException::withMessages(['cart' => 'El precio del teléfono no cubre su costo real.']);
        }
        $total = app(SaleDiscountCalculator::class)->limited($p->sale_price_cents, $p->historical_cost_cents, $input['discount_cents'] ?? 0, 'PHONE', 1, $discountRules);
        $phone = ['brand' => $p->variant->model->brand->name, 'model' => $p->variant->model->name, 'ram_gb' => $p->variant->ram_gb, 'storage_gb' => $p->variant->storage_gb, 'condition' => $p->condition, 'color' => $p->color, 'physical_state' => $p->physical_state, 'included_accessories' => $p->included_accessories, 'imeis' => array_values(PhoneSaleInput::imeis($p, $input, $requireImei))];
        $name = $phone['brand'].' '.$phone['model'].' · '.$phone['ram_gb'].'/'.$phone['storage_gb'].' GB';

        return ['product_id' => null, 'phone_unit_id' => $p->id, 'kind' => 'PHONE', 'phone_snapshot' => $phone, 'product_code' => $phone['imeis'][0] ?? 'EQUIPO-'.$p->id, 'product_name' => $name, 'product_description' => ($p->condition === 'NEW' ? 'Nuevo' : 'Usado').' · '.$p->color, 'product_image_path' => $p->image_path, 'quantity' => 1, 'unit_price_cents' => $p->sale_price_cents, 'total_cents' => $total, 'cost_cents' => $p->historical_cost_cents, 'previous_revision' => 0, 'before_quantity' => 1, 'after_quantity' => 0, 'before_value_cents' => $p->historical_cost_cents, 'after_value_cents' => 0];
    }
}
