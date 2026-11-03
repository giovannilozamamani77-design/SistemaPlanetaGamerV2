<?php

namespace App\Domain\Tarjetas\Models;

use Illuminate\Database\Eloquent\Model;

class CardInventoryCommand extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
