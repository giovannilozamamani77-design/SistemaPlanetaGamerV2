<?php

namespace App\Domain\Tarjetas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Read-only projection over card sales and Entel receipts; no physical table. */
class CardSaleRegisterEntry extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'sale_register';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'source_id' => 'integer', 'total_quantity' => 'integer', 'total_cents' => 'integer', 'customer_total_cents' => 'integer', 'bonus_rate_bps' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException('El registro es una vista de lectura.'));
        static::deleting(fn () => throw new LogicException('El historial no se elimina.'));
    }

    public function lines(): HasMany
    {
        // Signed projection keys prevent a digital receipt from matching physical sale lines.
        return $this->hasMany(CardSaleLine::class, 'card_sale_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
