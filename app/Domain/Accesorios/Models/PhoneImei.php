<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneImei extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['slot' => 'integer'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(PhoneUnit::class, 'unit_id');
    }
}
