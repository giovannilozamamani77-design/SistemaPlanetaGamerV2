<?php

namespace App\Domain\Tarjetas\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CardSaleLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['cost_cents', 'gross_margin_cents'];

    protected function casts(): array
    {
        return ['cost_cents' => 'integer', 'gross_margin_cents' => 'integer', 'quantity' => 'integer', 'unit_price_cents' => 'integer', 'total_cents' => 'integer', 'product_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El detalle de venta no se reescribe.'));
        static::deleting(fn () => throw new LogicException('El detalle de venta no se elimina.'));
    }
}
