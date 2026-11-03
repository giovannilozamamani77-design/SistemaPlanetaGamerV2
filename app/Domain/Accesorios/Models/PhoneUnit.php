<?php

namespace App\Domain\Accesorios\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhoneUnit extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['image_path', 'registration_snapshot', 'pricing_snapshot', 'historical_cost_cents', 'purchase_amount_cents', 'purchase_rate_micros', 'expenses_cents', 'payload_hash'];

    protected function casts(): array
    {
        return ['reservation_id' => 'integer', 'created_by' => 'integer', 'purchase_amount_cents' => 'integer', 'purchase_rate_micros' => 'integer', 'expenses_cents' => 'integer', 'historical_cost_cents' => 'integer', 'sale_price_cents' => 'integer', 'pricing_snapshot' => 'array', 'registration_snapshot' => 'array'];
    }

    public function scopeAvailableForSale(Builder $query): void
    {
        $query->where(function ($q): void {
            $q->where('status', 'AVAILABLE')->orWhere(function ($expired): void {
                $expired->where('status', 'RESERVED')->whereHas('reservation', fn ($r) => $r->where('expires_at', '<=', now())->where('status', 'ACTIVE'));
            });
        });
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(PhoneReservation::class, 'reservation_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PhoneVariant::class, 'variant_id');
    }

    public function imeis(): HasMany
    {
        return $this->hasMany(PhoneImei::class, 'unit_id')->orderBy('slot');
    }
}
