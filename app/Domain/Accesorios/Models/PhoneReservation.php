<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneReservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['cart_key'];

    protected function casts(): array
    {
        return ['unit_id' => 'integer', 'user_id' => 'integer', 'price_cents' => 'integer', 'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime'];
    }
}
