<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;

class PricingChange extends Model
{
    public $timestamps = false;

    protected $table = 'accessory_pricing_changes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'previous_revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El historial de precios no se reescribe.'));
        static::deleting(fn () => throw new \LogicException('El historial de precios no se elimina.'));
    }
}
