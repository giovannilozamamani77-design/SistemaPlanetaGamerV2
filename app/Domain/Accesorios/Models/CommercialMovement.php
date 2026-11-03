<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialMovement extends Model
{
    protected $table = 'accessory_commercial_movements';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fund_id' => 'integer', 'sale_id' => 'integer', 'user_id' => 'integer', 'amount_cents' => 'integer', 'before_cents' => 'integer', 'after_cents' => 'integer', 'previous_revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El movimiento de efectivo es inmutable.'));
        static::deleting(fn () => throw new \LogicException('El movimiento de efectivo no se borra.'));
    }
}
