<?php

namespace App\Domain\Tarjetas\Models;

use Illuminate\Database\Eloquent\Model;

class EntelDigitalAccount extends Model
{
    public const KINDS = ['CREDIT' => 'Crédito', 'PACKAGES' => 'Paquetes'];

    protected $guarded = ['id'];

    protected $hidden = ['cost_value_cents', 'cost_revision'];

    protected function casts(): array
    {
        return ['cost_value_cents' => 'integer', 'cost_revision' => 'integer', 'balance_cents' => 'integer', 'initialized_at' => 'immutable_datetime'];
    }
}
