<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhoneModel extends Model
{
    protected $guarded = ['id'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(PhoneBrand::class, 'brand_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(PhoneVariant::class, 'model_id');
    }
}
