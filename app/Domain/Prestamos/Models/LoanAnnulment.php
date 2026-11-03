<?php

namespace App\Domain\Prestamos\Models;

use Illuminate\Database\Eloquent\Model;

class LoanAnnulment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El comprobante confirmado no se reescribe.'));
        static::deleting(fn () => throw new \LogicException('El historial no se elimina.'));
    }
}
