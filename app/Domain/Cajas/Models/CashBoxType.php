<?php

namespace App\Domain\Cajas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashBoxType extends Model
{
    public function boxes(): HasMany
    {
        return $this->hasMany(CashBox::class, 'type', 'code');
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['components' => 'array', 'is_active' => 'boolean', 'revision' => 'integer'];
    }
}
