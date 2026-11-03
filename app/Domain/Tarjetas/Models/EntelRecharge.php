<?php

namespace App\Domain\Tarjetas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EntelRecharge extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['cost_cents', 'gross_margin_cents', 'entel_digital_movement_id', 'card_inventory_movement_id', 'request_key'];

    protected function casts(): array
    {
        return ['cost_cents' => 'integer', 'gross_margin_cents' => 'integer', 'amount_cents' => 'integer', 'bonus_rate_bps' => 'integer', 'customer_bonus_cents' => 'integer', 'customer_total_cents' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La recarga confirmada no se modifica.'));
        static::deleting(fn () => throw new LogicException('La recarga confirmada conserva su historial.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
