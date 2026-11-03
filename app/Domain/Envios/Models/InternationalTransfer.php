<?php

namespace App\Domain\Envios\Models;

use App\Domain\Cajas\Models\CashBox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternationalTransfer extends Model
{
    protected $guarded = ['id'];

    public function box(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'cash_box_id');
    }

    protected function casts(): array
    {
        return ['quote' => 'array', 'bob_cents' => 'integer', 'brl_cents' => 'integer', 'principal_cents' => 'integer', 'margin_cents' => 'integer', 'local_held_cents' => 'integer', 'admin_principal_cents' => 'integer', 'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
