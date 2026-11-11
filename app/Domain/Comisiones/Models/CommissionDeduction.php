<?php

namespace App\Domain\Comisiones\Models;

use App\Domain\ControlHorario\Models\TimeIncident;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionDeduction extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'time_incident_id' => 'integer', 'registered_by_user_id' => 'integer', 'amount_cents' => 'integer', 'before_pending_cents' => 'integer', 'after_pending_cents' => 'integer', 'snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(TimeIncident::class, 'time_incident_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El descuento de comisiones conserva su registro original.'));
        static::deleting(fn () => throw new \LogicException('El descuento de comisiones no se borra.'));
    }
}
