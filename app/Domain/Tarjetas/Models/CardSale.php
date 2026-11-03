<?php

namespace App\Domain\Tarjetas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CardSale extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'total_quantity' => 'integer', 'total_cents' => 'integer', 'cash_before_cents' => 'integer', 'cash_after_cents' => 'integer', 'wholesale_min_quantity' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La venta no se reescribe.'));
        static::deleting(fn () => throw new LogicException('La venta se conserva en el historial.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CardSaleLine::class);
    }
}
