<?php

namespace App\Domain\Tarjetas\Models;

use Illuminate\Database\Eloquent\Model;

class CardStore extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cash_balance_cents' => 'integer', 'revision' => 'integer', 'entel_credit_bonus_bps' => 'integer', 'entel_packages_bonus_bps' => 'integer', 'wholesale_min_quantity' => 'integer', 'initialized_at' => 'immutable_datetime', 'session_control_started_at' => 'immutable_datetime'];
    }
}
