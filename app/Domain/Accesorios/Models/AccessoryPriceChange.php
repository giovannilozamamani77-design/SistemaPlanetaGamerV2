<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;

class AccessoryPriceChange extends Model
{
    protected $table = 'accessory_product_price_changes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product_id' => 'integer', 'user_id' => 'integer', 'old_price_cents' => 'integer', 'new_price_cents' => 'integer', 'cost_basis_cents' => 'integer', 'pricing_revision' => 'integer', 'markup_bps' => 'integer', 'previous_revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El cambio de precio es inmutable.'));
        static::deleting(fn () => throw new \LogicException('El cambio de precio no se borra.'));
    }
}
