<?php

namespace App\Domain\Comisiones\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionDeductionReversal extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['deduction_id' => 'integer', 'voided_by_user_id' => 'integer', 'amount_cents' => 'integer', 'before_pending_cents' => 'integer', 'after_pending_cents' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function deduction(): BelongsTo
    {
        return $this->belongsTo(CommissionDeduction::class, 'deduction_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('La anulación del descuento conserva su registro original.'));
        static::deleting(fn () => throw new \LogicException('La anulación del descuento no se borra.'));
    }
}
