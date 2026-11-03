<?php

namespace App\Domain\Incidencias\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EconomicIncident extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['receipt_justified_at' => 'immutable_datetime', 'policy_snapshot' => 'array', 'occurred_at' => 'immutable_datetime', 'amount_cents' => 'integer', 'pending_cents' => 'integer', 'forgiven_cents' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(RecoveryPayment::class);
    }

    public function latestRecovery(): HasOne
    {
        return $this->hasOne(RecoveryPayment::class)->latestOfMany();
    }
}
