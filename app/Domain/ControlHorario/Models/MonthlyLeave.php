<?php

namespace App\Domain\ControlHorario\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyLeave extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'DISPONIBLE'];

    protected function casts(): array
    {
        return ['used_at' => 'immutable_datetime'];
    }
}
