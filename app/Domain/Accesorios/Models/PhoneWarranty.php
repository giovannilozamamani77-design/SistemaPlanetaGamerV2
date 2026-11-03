<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneWarranty extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'expires_on' => 'immutable_date', 'months' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('La garantía emitida es inmutable.'));
        static::deleting(fn () => throw new \LogicException('La garantía no se borra.'));
    }
}
