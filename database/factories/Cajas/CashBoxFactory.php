<?php

namespace Database\Factories\Cajas;

use App\Domain\Cajas\Models\CashBox;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashBox> */
class CashBoxFactory extends Factory
{
    protected $model = CashBox::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('CAJA-####??'), 'name' => 'Caja '.fake()->unique()->numberBetween(1, 99999),
            'type' => 'BCP', 'base_capital_cents' => 2000000, 'is_active' => true, 'revision' => 1, 'created_by' => User::factory()];
    }
}
