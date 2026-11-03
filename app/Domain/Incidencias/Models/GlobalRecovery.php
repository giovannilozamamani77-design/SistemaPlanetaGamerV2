<?php

namespace App\Domain\Incidencias\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GlobalRecovery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'confirmed_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function declarant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(RecoveryPayment::class);
    }
}
