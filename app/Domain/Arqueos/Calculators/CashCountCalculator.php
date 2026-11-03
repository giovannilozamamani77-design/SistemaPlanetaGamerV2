<?php

namespace App\Domain\Arqueos\Calculators;

use App\Support\Bolivianos;

class CashCountCalculator
{
    public const BILLS = ['b200' => 20000, 'b100' => 10000, 'b50' => 5000, 'b20' => 2000, 'b10' => 1000];

    public const COINS = ['m5' => 500, 'm2' => 200, 'm1' => 100, 'm050' => 50, 'm020' => 20, 'm010' => 10];

    public const DENOMINATIONS = self::BILLS + self::COINS;

    public function calculate(array $quantities, array $packedAmounts): array
    {
        $errors = [];
        foreach (array_diff_key($quantities, self::DENOMINATIONS) as $key => $unused) {
            $errors['quantities.'.$key] = 'Esta denominación no está disponible.';
        }
        foreach (array_diff_key($packedAmounts, self::DENOMINATIONS) as $key => $unused) {
            $errors['packed_amounts.'.$key] = 'Esta denominación no está disponible.';
        }
        $normalized = $packed = $rows = [];
        $looseTotal = $packedTotal = 0;
        foreach (self::DENOMINATIONS as $key => $denomination) {
            $pieces = $quantities[$key] ?? '';
            $amount = $packedAmounts[$key] ?? '';
            $pieces = $pieces === '' ? '0' : $pieces;
            $amount = $amount === '' ? '0' : $amount;
            if ((! is_string($pieces) && ! is_int($pieces)) || ! preg_match('/\A[0-9]{1,6}\z/', (string) $pieces)) {
                $errors['quantities.'.$key] = 'Usa piezas enteras de cero a 999999.';
            }
            if ((! is_string($amount) && ! is_int($amount)) || ! preg_match('/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/', (string) $amount)) {
                $errors['packed_amounts.'.$key] = 'Usa un importe no negativo con hasta dos decimales.';
            }
            if (isset($errors['quantities.'.$key]) || isset($errors['packed_amounts.'.$key])) {
                continue;
            }
            $normalized[$key] = (int) $pieces;
            $packed[$key] = Bolivianos::cents((string) $amount);
            $loose = $normalized[$key] * $denomination;
            $looseTotal += $loose;
            $packedTotal += $packed[$key];
            $rows[$key] = $loose + $packed[$key];
        }
        $total = $looseTotal + $packedTotal;
        if ($total > 99999999999) {
            $errors['total'] = 'El total supera el importe máximo permitido.';
        }
        if ($errors !== []) {
            return ['valid' => false, 'errors' => $errors, 'snapshot' => null];
        }

        return ['valid' => true, 'errors' => [], 'snapshot' => ['version' => 1, 'quantities' => $normalized,
            'denominations_cents' => self::DENOMINATIONS, 'packed_amounts_cents' => $packed,
            'row_total_cents' => $rows, 'loose_total_cents' => $looseTotal,
            'packed_total_cents' => $packedTotal, 'total_cents' => $total]];
    }

    public function recalculateSnapshot(array $input): array
    {
        $invalid = ['valid' => false, 'errors' => ['snapshot' => 'Revisa las piezas y los importes del arqueo.'], 'snapshot' => null];
        if (! is_array($input['quantities'] ?? []) || ! is_array($input['packed_amounts_cents'] ?? [])) {
            return $invalid;
        }
        $packed = [];
        foreach ($input['packed_amounts_cents'] ?? [] as $key => $cents) {
            if (! is_int($cents) || $cents < 0) {
                return $invalid;
            }
            $packed[$key] = Bolivianos::format($cents);
        }

        return $this->calculate($input['quantities'] ?? [], $packed);
    }

    public function matchesCash(array $snapshot, mixed $amount): bool
    {
        $amount = $amount === '' || $amount === null ? '0' : $amount;
        if ((! is_string($amount) && ! is_int($amount)) || ! preg_match('/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/', (string) $amount)) {
            return false;
        }

        return Bolivianos::cents((string) $amount) === $snapshot['total_cents'];
    }
}
