<?php

namespace App\Domain\Accesorios\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccessorySaleInput
{
    public static function basket(array $data): array
    {
        $phones = PhoneSaleInput::basket($data);
        if ($phones) {
            $data['lines'] ??= [];
        }
        $v = Validator::make($data, ['lines' => $phones ? ['present', 'array', 'max:25'] : ['required', 'array', 'min:1', 'max:25'], 'lines.*' => ['required', 'array'], 'lines.*.discount' => ['nullable'], 'lines.*.product_id' => ['required', 'integer', 'min:1', 'distinct:strict'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'], 'lines.*.expected_revision' => ['required', 'integer', 'min:0'], 'lines.*.expected_price_cents' => ['required', 'integer', 'min:1', 'max:'.PricingInput::MAX_CENTS]])->validate();
        if (count($v['lines']) + count($phones) > 25) {
            throw ValidationException::withMessages(['cart' => 'La venta admite hasta 25 productos distintos.']);
        }
        $lines = [];
        foreach ($v['lines'] as $index => $line) {
            $normalized = ['product_id' => (int) $line['product_id'], 'quantity' => (int) $line['quantity'], 'expected_revision' => (int) $line['expected_revision'], 'expected_price_cents' => (int) $line['expected_price_cents']];
            $discount = filled($line['discount'] ?? null) ? PricingInput::money($line['discount'], 'discount', true) : 0;
            if ($discount > 0) {
                $normalized['discount_cents'] = $discount;
            }
            $lines[] = $normalized;
        }
        usort($lines, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
        if (count(array_unique(array_column($lines, 'product_id'))) !== count($lines)) {
            throw ValidationException::withMessages(['lines' => 'Un accesorio solo puede aparecer una vez.']);
        }

        return $lines;
    }

    public static function withDiscounts(array $data, array $discounts): array
    {
        foreach (['lines' => ['a_', 'product_id'], 'phone_lines' => ['p_', 'unit_id']] as $key => [$prefix, $id]) {
            foreach ($data[$key] ?? [] as $index => $line) {
                $data[$key][$index]['discount'] = $discounts[$prefix.$line[$id]] ?? '0';
            }
        }

        return $data;
    }

    public static function withFinalPrices(array $data, array $prices): array
    {
        foreach (['lines' => ['a_', 'product_id'], 'phone_lines' => ['p_', 'unit_id']] as $key => [$prefix,$id]) {
            foreach ($data[$key] ?? [] as $i => $line) {
                $field = 'final_prices.'.$prefix.$line[$id];
                $gross = $line['expected_price_cents'] * ($line['quantity'] ?? 1);
                $net = PricingInput::money($prices[$prefix.$line[$id]] ?? (string) ($gross / 100), $field, true);
                if ($net > $gross) {
                    throw ValidationException::withMessages([$field => 'El total final no puede superar el precio del catálogo.']);
                }
                $data[$key][$i]['discount'] = number_format(($gross - $net) / 100, 2, '.', '');
            }
        }

        return $data;
    }

    public static function payment(array $data): array
    {
        return Validator::make($data, ['rules_revisions' => ['sometimes', 'array'], 'rules_revisions.*' => ['integer', 'min:0'], 'request_key' => ['required', 'uuid'], 'payment_method' => ['required', Rule::in(['CASH'])], 'cash_received' => ['required', 'accepted']])->validate();
    }
}
