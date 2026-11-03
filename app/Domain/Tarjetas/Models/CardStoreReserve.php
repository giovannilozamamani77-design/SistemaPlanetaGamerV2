<?php

namespace App\Domain\Tarjetas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardStoreReserve extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['original_amount' => 'integer', 'held_amount' => 'integer', 'missing_amount' => 'integer', 'expires_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CardProduct::class, 'card_product_id');
    }
}
