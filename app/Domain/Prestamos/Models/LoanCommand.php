<?php

namespace App\Domain\Prestamos\Models;

use Illuminate\Database\Eloquent\Model;

class LoanCommand extends Model
{
    public $timestamps = false;

    protected $table = 'loan_commands';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El registro confirmado no se reescribe.'));
        static::deleting(fn () => throw new \LogicException('El historial no se elimina.'));
    }
}
