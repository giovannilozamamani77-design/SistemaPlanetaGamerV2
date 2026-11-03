<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeQuote extends Model
{
    public $timestamps = false;

    protected $table = 'accessory_exchange_quotes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate_micros' => 'integer', 'source_at' => 'immutable_datetime', 'fetched_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('La referencia guardada no se reescribe.'));
        static::deleting(fn () => throw new \LogicException('La referencia guardada no se elimina.'));
    }
}
