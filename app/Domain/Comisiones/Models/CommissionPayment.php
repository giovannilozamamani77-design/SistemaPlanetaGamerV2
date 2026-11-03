<?php

namespace App\Domain\Comisiones\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CommissionPayment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'registered_by_user_id' => 'integer', 'amount_cents' => 'integer', 'earned_cents' => 'integer', 'before_paid_cents' => 'integer', 'before_pending_cents' => 'integer', 'after_pending_cents' => 'integer', 'created_at' => 'immutable_datetime', 'reversed_at' => 'immutable_datetime', 'reversal_id' => 'integer'];
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(CommissionPaymentReversal::class, 'payment_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereDoesntHave('reversal');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El pago de comisiones es inmutable.'));
        static::deleting(fn () => throw new \LogicException('El pago de comisiones no se borra.'));
    }
}
