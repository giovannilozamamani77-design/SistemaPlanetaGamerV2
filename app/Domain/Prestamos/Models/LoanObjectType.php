<?php

namespace App\Domain\Prestamos\Models;

use Illuminate\Database\Eloquent\Model;

class LoanObjectType extends Model
{
    public $timestamps = false;

    protected $table = 'loan_object_types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate_bps' => 'integer', 'is_active' => 'boolean', 'revision' => 'integer'];
    }
}
