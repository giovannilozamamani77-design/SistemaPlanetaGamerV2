<?php

namespace App\Domain\Cajas\Models;

use Illuminate\Database\Eloquent\Model;

class CashBoxAudit extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
