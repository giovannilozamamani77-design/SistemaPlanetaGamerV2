<?php

namespace App\Domain\Cajas\Models;

use Illuminate\Database\Eloquent\Model;

class CashBoxConfiguration extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_count_pending' => 'boolean', 'accounts' => 'array', 'shifts' => 'array', 'base_capital_cents' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
