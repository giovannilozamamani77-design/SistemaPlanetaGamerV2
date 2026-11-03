<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccessoryCashDeposit extends Model
{
    protected $table = 'accessory_cash_deposits';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['destination_snapshot' => 'array', 'amount_cents' => 'integer', 'user_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El registro es inmutable.'));
        static::deleting(fn () => throw new \LogicException('El historial no se borra.'));
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(AccessoryCashDepositReversal::class, 'deposit_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(CommercialMovement::class);
    }
}
