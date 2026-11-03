<?php

namespace App\Domain\Tarjetas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CardInventoryMovement extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['cost_before_cents', 'cost_after_cents'];

    protected function casts(): array
    {
        return ['cost_before_cents' => 'integer', 'cost_after_cents' => 'integer', 'quantity' => 'integer', 'unit_cost_cents' => 'integer', 'total_cost_cents' => 'integer', 'cash_delta_cents' => 'integer', 'stock_before' => 'integer', 'stock_after' => 'integer', 'cash_before_cents' => 'integer', 'cash_after_cents' => 'integer', 'product_snapshot' => 'array', 'destination_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El movimiento se conserva; registra una corrección auditada.'));
        static::deleting(fn () => throw new LogicException('El historial de inventario no se elimina.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CardProduct::class, 'card_product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
