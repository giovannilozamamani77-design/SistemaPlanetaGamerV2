<?php

namespace App\Domain\Tarjetas\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CardDepositAccount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Desactiva la cuenta; no se elimina su historial.'));
    }

    public function snapshot(): array
    {
        return $this->only(['id', 'name', 'bank', 'number', 'holder', 'qr_path', 'qr_sha256', 'revision']);
    }
}
