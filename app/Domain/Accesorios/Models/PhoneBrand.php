<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhoneBrand extends Model
{
    protected $guarded = ['id'];

    public function models(): HasMany
    {
        return $this->hasMany(PhoneModel::class, 'brand_id');
    }
}
