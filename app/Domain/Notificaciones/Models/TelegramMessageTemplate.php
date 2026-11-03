<?php

namespace App\Domain\Notificaciones\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramMessageTemplate extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['body', 'image'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'image' => 'encrypted:array', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Restaura la plantilla; no borres su historial.'));
    }
}
