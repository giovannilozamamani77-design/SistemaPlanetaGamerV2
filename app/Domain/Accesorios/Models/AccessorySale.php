<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessorySale extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['customer_snapshot', 'cost_cents', 'payload_hash', 'request_key'];

    protected function casts(): array
    {
        return ['customer_id' => 'integer', 'customer_snapshot' => 'array', 'receipt_required' => 'boolean', 'user_id' => 'integer', 'quantity' => 'integer', 'total_cents' => 'integer', 'cost_cents' => 'integer', 'pricing_revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AccessorySaleLine::class, 'sale_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('La venta confirmada es inmutable.'));
        static::deleting(fn () => throw new \LogicException('La venta confirmada no se borra.'));
    }
}
