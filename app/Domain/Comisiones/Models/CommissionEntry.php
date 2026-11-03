<?php

namespace App\Domain\Comisiones\Models;

use App\Domain\Accesorios\Models\AccessorySaleLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['snapshot'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(AccessorySaleLine::class, 'sale_line_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('La comisión ganada es inmutable.'));
        static::deleting(fn () => throw new \LogicException('La comisión ganada no se borra.'));
    }
}
