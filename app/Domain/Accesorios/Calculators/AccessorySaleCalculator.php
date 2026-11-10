<?php

namespace App\Domain\Accesorios\Calculators;

use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryProduct;
use Illuminate\Validation\ValidationException;

class AccessorySaleCalculator
{
    public function line(AccessoryProduct $p, array $line, ?array $discountRules = null): array
    {
        if ($p->revision !== $line['expected_revision'] || $p->sale_price_cents !== $line['expected_price_cents']) {
            throw ValidationException::withMessages(['cart' => 'El stock o precio de '.$p->name.' cambió. Retira ese accesorio y agrégalo nuevamente.']);
        }
        $sold = $line['quantity'];
        if ($sold < 1 || $sold > $p->quantity) {
            throw ValidationException::withMessages(['cart' => 'No hay stock suficiente de '.$p->name.'.']);
        }
        if (! $p->cost_pending && ($p->stock_value_cents < $p->quantity || $p->sale_price_cents * $p->quantity < $p->stock_value_cents)) {
            throw ValidationException::withMessages(['cart' => 'El precio de '.$p->name.' no cubre su costo. Contacta con Administración.']);
        }
        $total = $sold * $p->sale_price_cents;
        if ($total > PricingInput::MAX_CENTS) {
            throw ValidationException::withMessages(['cart' => 'El importe supera el límite por venta.']);
        }
        $cost = $sold === $p->quantity ? $p->stock_value_cents : intdiv($p->stock_value_cents * $sold + intdiv($p->quantity, 2), $p->quantity);

        $minimum = intdiv($p->stock_value_cents * $sold + $p->quantity - 1, $p->quantity);
        if ($p->cost_pending && ($line['discount_cents'] ?? 0) > 0) {
            throw ValidationException::withMessages(['cart' => 'Vende '.$p->name.' al precio registrado mientras su costo esté pendiente.']);
        }
        $total = app(SaleDiscountCalculator::class)->limited($total, $minimum, $line['discount_cents'] ?? 0, 'ACCESSORY', $sold, $discountRules);

        return ['product_id' => $p->id, 'product_code' => $p->code, 'product_name' => $p->name, 'product_description' => $p->description, 'product_image_path' => $p->image_path, 'quantity' => $sold, 'unit_price_cents' => $p->sale_price_cents, 'total_cents' => $total, 'cost_cents' => $p->cost_pending ? null : $cost, 'previous_revision' => $p->revision, 'before_quantity' => $p->quantity, 'after_quantity' => $p->quantity - $sold, 'before_value_cents' => $p->stock_value_cents, 'after_value_cents' => $p->stock_value_cents - $cost];
    }

    public function totals(array $lines): array
    {
        $total = array_sum(array_column($lines, 'total_cents'));
        $costs = array_column($lines, 'cost_cents');
        $cost = in_array(null, $costs, true) ? null : array_sum($costs);
        if ($total > PricingInput::MAX_CENTS) {
            throw ValidationException::withMessages(['cart' => 'El importe supera el límite por venta.']);
        }

        return ['quantity' => array_sum(array_column($lines, 'quantity')), 'total_cents' => $total, 'cost_cents' => $cost];
    }
}
