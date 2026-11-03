<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhoneVariant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ram_gb' => 'integer', 'storage_gb' => 'integer'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(PhoneModel::class, 'model_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(PhoneUnit::class, 'variant_id');
    }

    public function availableUnits(): HasMany
    {
        return $this->units()->availableForSale();
    }
}
