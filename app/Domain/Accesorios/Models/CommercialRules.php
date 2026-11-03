<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialRules extends Model
{
    public $timestamps = false;

    protected $table = 'commercial_sale_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'revision' => 'integer'];
    }

    public static function defaults(string $scope): array
    {
        return $scope === 'DISCOUNTS'
            ? ['phone_max_cents' => 3000, 'ranges' => [['from_cents' => 0, 'amount_cents' => 0], ['from_cents' => 5000, 'amount_cents' => 500]]]
            : ['enabled' => true, 'phone_bps' => 2000, 'ranges' => [['from_cents' => 0, 'amount_cents' => 0], ['from_cents' => 1000, 'amount_cents' => 100], ['from_cents' => 10001, 'amount_cents' => 200], ['from_cents' => 20001, 'amount_cents' => 400], ['from_cents' => 50000, 'amount_cents' => 600]]];
    }

    public static function current(string $scope): array
    {
        $row = static::where('scope', $scope)->first();

        return ['revision' => $row?->revision ?? 0, 'rules' => $row?->rules ?? static::defaults($scope)];
    }

    public static function bracket(int $price, array $ranges): int
    {
        $amount = 0;
        foreach ($ranges as $range) {
            if ($price >= $range['from_cents']) {
                $amount = $range['amount_cents'];
            }
        }

        return $amount;
    }
}
