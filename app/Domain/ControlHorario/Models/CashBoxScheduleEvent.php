<?php

namespace App\Domain\ControlHorario\Models;

use Illuminate\Database\Eloquent\Model;

class CashBoxScheduleEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }
}
