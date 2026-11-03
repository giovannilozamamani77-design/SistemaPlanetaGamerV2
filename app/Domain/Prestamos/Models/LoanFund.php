<?php

namespace App\Domain\Prestamos\Models;

use Illuminate\Database\Eloquent\Model;

class LoanFund extends Model
{
    public $timestamps = false;

    protected $table = 'loan_funds';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['balance_cents' => 'integer', 'revision' => 'integer', 'initialized_at' => 'immutable_datetime'];
    }
}
