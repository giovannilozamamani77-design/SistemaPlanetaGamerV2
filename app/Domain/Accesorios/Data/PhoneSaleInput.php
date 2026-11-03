<?php

namespace App\Domain\Accesorios\Data;

use App\Domain\Accesorios\Models\PhoneImei;
use App\Domain\Accesorios\Models\PhoneUnit;
use App\Domain\Prestamos\Data\LoanInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PhoneSaleInput
{
    public static function basket(array $data): array
    {
        $v = Validator::make($data, ['phone_lines' => ['sometimes', 'array', 'max:25'], 'phone_lines.*.discount' => ['nullable'], 'phone_lines.*.unit_id' => ['required', 'integer', 'min:1', 'distinct'], 'phone_lines.*.reservation_id' => ['required', 'integer', 'min:1', 'distinct'], 'phone_lines.*.imei1' => ['nullable', 'string', 'regex:/\A[0-9]{15}\z/'], 'phone_lines.*.imei2' => ['nullable', 'string', 'regex:/\A[0-9]{15}\z/'], 'phone_lines.*.expected_price_cents' => ['required', 'integer', 'min:1', 'max:'.PricingInput::MAX_CENTS]])->validate();
        $out = [];
        foreach ($v['phone_lines'] ?? [] as $line) {
            $normalized = ['unit_id' => (int) $line['unit_id'], 'reservation_id' => (int) $line['reservation_id'], 'expected_price_cents' => (int) $line['expected_price_cents']];
            foreach (['imei1', 'imei2'] as $key) {
                if (filled($line[$key] ?? null)) {
                    $normalized[$key] = $line[$key];
                }
            }
            $discount = filled($line['discount'] ?? null) ? PricingInput::money($line['discount'], 'discount', true) : 0;
            if ($discount > 0) {
                $normalized['discount_cents'] = $discount;
            }
            $out[] = $normalized;
        }
        usort($out, fn ($a, $b) => $a['unit_id'] <=> $b['unit_id']);
        if (count(array_unique(array_column($out, 'unit_id'))) !== count($out)) {
            throw ValidationException::withMessages(['cart' => 'Un teléfono solo puede aparecer una vez.']);
        }

        return $out;
    }

    public static function imeis(PhoneUnit $phone, array $input, bool $required): array
    {
        $out = [];
        foreach ([1, 2] as $slot) {
            $existing = $phone->imeis->firstWhere('slot', $slot)?->imei;
            $value = $input['imei'.$slot] ?? $existing;
            if ($existing && $value !== $existing) {
                throw ValidationException::withMessages(['cart' => 'El IMEI no coincide con el registrado para este equipo.']);
            }
            if ($value) {
                Validator::make(['imei' => $value], ['imei' => ['string', 'regex:/\A[0-9]{15}\z/']])->validate();
                if (in_array($value, $out, true) || PhoneImei::where('imei', $value)->where('unit_id', '!=', $phone->id)->exists()) {
                    throw ValidationException::withMessages(['cart' => 'Este IMEI ya pertenece a otro equipo o está repetido.']);
                }
                $out[$slot] = $value;
            }
        }
        if ($required && ! isset($out[1])) {
            throw ValidationException::withMessages(['cart' => 'Registra el IMEI del teléfono antes de confirmar la venta.']);
        }

        return $out;
    }

    public static function customer(array $data): array
    {
        $v = Validator::make($data, ['customer.ci' => ['required', 'string', 'max:60'], 'customer.name' => ['required', 'string', 'max:160'], 'customer.phone' => ['required', 'string', 'regex:/\A[0-9]{8}\z/'], 'customer.email' => ['nullable', 'email', 'max:160'], 'customer.email2' => ['nullable', 'email', 'max:160'], 'warranty_accepted' => ['required', 'accepted']])->validate()['customer'];
        $v['ci'] = LoanInput::normalize($v['ci']);
        $v['name'] = trim($v['name']);
        if ($v['ci'] === '' || $v['name'] === '') {
            throw ValidationException::withMessages(['customer.name' => 'Completa el CI y el nombre del cliente.']);
        }
        $v['email'] = filled($v['email'] ?? null) ? strtolower(trim($v['email'])) : null;
        $v['email2'] = filled($v['email2'] ?? null) ? strtolower(trim($v['email2'])) : null;

        return ['ci' => $v['ci'], 'name' => $v['name'], 'phone' => $v['phone'], 'email' => $v['email'], 'email2' => $v['email2']];
    }
}
