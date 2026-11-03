<?php

namespace App\Domain\Tarjetas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardStock extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['cost_value_cents', 'cost_revision'];

    protected function casts(): array
    {
        return ['cost_value_cents' => 'integer', 'cost_revision' => 'integer', 'quantity' => 'integer'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(CardStore::class, 'card_store_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CardProduct::class, 'card_product_id');
    }
}
