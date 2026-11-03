<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;

class AccessoryProductRegistration extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product_id' => 'integer', 'user_id' => 'integer', 'snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El alta registrada es inmutable.'));
        static::deleting(fn () => throw new \LogicException('El alta registrada no se borra.'));
    }
}
