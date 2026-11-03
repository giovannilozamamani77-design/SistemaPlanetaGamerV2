<?php

namespace App\Domain\Notificaciones\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramConfigurationChange extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload_hash', 'request_key'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El historial de configuración es inmutable.'));
        static::deleting(fn () => throw new \LogicException('El historial de configuración no se borra.'));
    }
}
