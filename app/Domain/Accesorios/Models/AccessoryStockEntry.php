<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessoryStockEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product_id' => 'integer', 'user_id' => 'integer', 'quantity' => 'integer', 'unit_cost_cents' => 'integer', 'total_cost_cents' => 'integer', 'previous_revision' => 'integer', 'before_quantity' => 'integer', 'after_quantity' => 'integer', 'before_value_cents' => 'integer', 'after_value_cents' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Una entrada confirmada no se modifica.'));
        static::deleting(fn () => throw new \LogicException('Una entrada confirmada no se borra.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(AccessoryProduct::class, 'product_id');
    }
}
