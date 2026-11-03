<?php

namespace App\Domain\Incidencias\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecoveryPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime', 'amount_cents' => 'integer'];
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id')->select(['id', 'name']);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by')->select(['id', 'name']);
    }

    public function economicIncident(): BelongsTo
    {
        return $this->belongsTo(EconomicIncident::class);
    }
}
