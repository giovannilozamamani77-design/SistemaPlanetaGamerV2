<?php

namespace App\Domain\Notificaciones\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramDeliveryAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function ($attempt) {
            if ($attempt->getOriginal('status') !== 'PROCESANDO') {
                throw new \LogicException('El intento finalizado se conserva.');
            }
        });
        static::deleting(fn () => throw new \LogicException('El historial de intentos se conserva.'));
    }
}
