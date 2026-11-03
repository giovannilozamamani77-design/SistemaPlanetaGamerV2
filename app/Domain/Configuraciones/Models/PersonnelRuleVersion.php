<?php

namespace App\Domain\Configuraciones\Models;

use Illuminate\Database\Eloquent\Model;

class PersonnelRuleVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['values' => 'array', 'effective_from' => 'immutable_datetime'];
    }
}
