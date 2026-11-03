<?php

namespace App\Domain\Envios\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangePolicy extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['base_rate' => 'integer', 'buy_rate' => 'integer', 'sell_rate' => 'integer', 'enabled' => 'boolean', 'reference_enabled' => 'boolean', 'created_at' => 'immutable_datetime'];
    }
}
