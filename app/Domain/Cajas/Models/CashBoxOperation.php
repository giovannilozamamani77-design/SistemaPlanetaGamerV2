<?php

namespace App\Domain\Cajas\Models;

use Illuminate\Database\Eloquent\Model;

class CashBoxOperation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['commission_only' => 'boolean', 'deltas' => 'array', 'amount_cents' => 'integer', 'fee_cents' => 'integer', 'suggested_fee_cents' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
