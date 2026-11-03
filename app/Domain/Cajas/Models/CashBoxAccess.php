<?php

namespace App\Domain\Cajas\Models;

use Illuminate\Database\Eloquent\Model;

class CashBoxAccess extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expected_balances' => 'array', 'verified_at' => 'immutable_datetime'];
    }
}
