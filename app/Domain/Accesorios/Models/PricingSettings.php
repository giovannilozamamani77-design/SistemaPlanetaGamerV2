<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;

class PricingSettings extends Model
{
    public $timestamps = false;

    protected $table = 'accessory_pricing_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'physical_rate_micros' => 'integer', 'adjustment_micros' => 'integer', 'auto_refresh' => 'boolean', 'revision' => 'integer', 'updated_at' => 'immutable_datetime'];
    }
}
