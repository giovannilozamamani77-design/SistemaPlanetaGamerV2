<?php

namespace App\Domain\Accesorios\Data;

use App\Support\Bolivianos;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PricingInput
{
    public const SCALE = 1000000;

    public const MAX_CENTS = 999999999;

    public const KINDS = ['ACCESSORY' => 'Accesorios', 'USED_PHONE' => 'Teléfonos usados', 'NEW_PHONE' => 'Teléfonos nuevos'];

    public const PREFIXES = ['ACCESSORY' => 'accessory', 'USED_PHONE' => 'used_phone', 'NEW_PHONE' => 'new_phone'];

    public static function decimal(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages(['amount' => 'Ingresa un número válido, sin separadores de miles.']);
        }

        return str_replace(',', '.', trim((string) $value));
    }

    public static function rate(mixed $value, string $field, bool $zero = false): int
    {
        try {
            $text = self::decimal($value);
            if (! preg_match('/\A([0-9]{1,4})(?:\.([0-9]{1,6}))?\z/', $text, $m)) {
                throw new \InvalidArgumentException;
            }
            $rate = ((int) $m[1]) * self::SCALE + (int) str_pad($m[2] ?? '', 6, '0');
            if ($rate > 1000 * self::SCALE || (! $zero && $rate === 0)) {
                throw new \InvalidArgumentException;
            }

            return $rate;
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Usa un valor entre '.($zero ? '0' : '0.000001').' y 1000, con hasta seis decimales.']);
        }
    }

    public static function rateText(?int $rate): string
    {
        if ($rate === null) {
            return '';
        }
        $text = intdiv($rate, self::SCALE).'.'.str_pad((string) ($rate % self::SCALE), 6, '0', STR_PAD_LEFT);

        return rtrim(rtrim($text, '0'), '.');
    }

    public static function money(mixed $value, string $field, bool $zero = false): int
    {
        try {
            $cents = Bolivianos::cents(self::decimal($value));
            if ((! $zero && $cents === 0) || $cents > self::MAX_CENTS) {
                throw new \InvalidArgumentException;
            }

            return $cents;
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Ingresa un importe válido con hasta dos decimales y máximo 9999999.99.']);
        }
    }

    public static function percent(mixed $value, string $field): int
    {
        try {
            $bps = self::money($value, $field, true);
        } catch (ValidationException) {
            throw ValidationException::withMessages([$field => 'Ingresa un porcentaje entre 0 y 10000, con hasta dos decimales.']);
        }
        if ($bps > 1000000) {
            throw ValidationException::withMessages([$field => 'El porcentaje no puede superar 10000 %.']);
        }

        return $bps;
    }

    public static function configuration(array $data): array
    {
        $rules = ['exchange_mode' => ['required', Rule::in(['PHYSICAL', 'REFERENCE'])], 'physical_rate' => ['nullable'], 'adjustment' => ['required'], 'auto_refresh' => ['required', 'boolean'], 'new_phone_auto_update' => ['sometimes', 'boolean']];
        foreach (self::PREFIXES as $prefix) {
            $rules[$prefix.'_mode'] = ['required', Rule::in(['MANUAL', 'PERCENT'])];
            $rules[$prefix.'_percent'] = ['nullable'];
        }
        $v = Validator::make($data, $rules)->validate();
        $values = ['exchange_mode' => 'REFERENCE', 'physical_rate_micros' => filled($v['physical_rate'] ?? null) ? self::rate($v['physical_rate'], 'physical_rate') : null, 'adjustment_micros' => self::rate($v['adjustment'], 'adjustment', true), 'auto_refresh' => (bool) $v['auto_refresh'], 'rules' => []];
        foreach (self::PREFIXES as $kind => $prefix) {
            $values['rules'][$kind] = ['mode' => $v[$prefix.'_mode'], 'markup_bps' => $v[$prefix.'_mode'] === 'PERCENT' ? self::percent($v[$prefix.'_percent'] ?? '', $prefix.'_percent') : 0];
        }

        if ($v['new_phone_auto_update'] ?? false) {
            if ($v['new_phone_mode'] !== 'PERCENT') {
                throw ValidationException::withMessages(['new_phone_auto_update' => 'Configura un porcentaje para actualizar precios automáticamente.']);
            }
            $values['rules']['NEW_PHONE']['auto_update'] = true;
        }

        return $values;
    }

    public static function signedAmount(int $cents): string
    {
        return ($cents < 0 ? '-' : '').Bolivianos::formatCompact(abs($cents));
    }

    public static function defaults(): array
    {
        return ['exchange_mode' => 'REFERENCE', 'physical_rate_micros' => null, 'adjustment_micros' => 700000, 'auto_refresh' => true, 'rules' => array_fill_keys(array_keys(self::KINDS), ['mode' => 'MANUAL', 'markup_bps' => 0]), 'revision' => 0];
    }
}
