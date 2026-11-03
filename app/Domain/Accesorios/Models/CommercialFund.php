<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialFund extends Model
{
    protected $table = 'accessory_commercial_funds';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['balance_cents' => 'integer', 'revision' => 'integer'];
    }
}
