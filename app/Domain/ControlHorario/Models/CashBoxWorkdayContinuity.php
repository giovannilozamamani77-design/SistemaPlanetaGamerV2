<?php

namespace App\Domain\ControlHorario\Models;

use Illuminate\Database\Eloquent\Model;

class CashBoxWorkdayContinuity extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime', 'effective_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }
}
