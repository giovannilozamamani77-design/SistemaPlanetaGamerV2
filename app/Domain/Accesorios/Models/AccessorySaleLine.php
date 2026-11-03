<?php

namespace App\Domain\Accesorios\Models;

use App\Domain\Comisiones\Models\CommissionEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccessorySaleLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['product_image_path', 'cost_cents', 'before_value_cents', 'after_value_cents'];

    protected function casts(): array
    {
        return ['phone_unit_id' => 'integer', 'phone_snapshot' => 'array', 'sale_id' => 'integer', 'product_id' => 'integer', 'quantity' => 'integer', 'unit_price_cents' => 'integer', 'total_cents' => 'integer', 'cost_cents' => 'integer', 'previous_revision' => 'integer', 'before_quantity' => 'integer', 'after_quantity' => 'integer', 'before_value_cents' => 'integer', 'after_value_cents' => 'integer'];
    }

    public function commission(): HasOne
    {
        return $this->hasOne(CommissionEntry::class, 'sale_line_id');
    }

    public function warranty(): HasOne
    {
        return $this->hasOne(PhoneWarranty::class, 'sale_line_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(AccessorySale::class, 'sale_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El detalle de venta es inmutable.'));
        static::deleting(fn () => throw new \LogicException('El detalle de venta no se borra.'));
    }
}
