<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialRuleChange extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before_rules' => 'array', 'after_rules' => 'array', 'previous_revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('La configuración histórica es inmutable.'));
        static::deleting(fn () => throw new \LogicException('La configuración histórica no se borra.'));
    }
}
