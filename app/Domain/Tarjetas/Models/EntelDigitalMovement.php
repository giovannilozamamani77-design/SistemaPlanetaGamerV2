<?php

namespace App\Domain\Tarjetas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EntelDigitalMovement extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['cost_before_cents', 'cost_after_cents'];

    protected function casts(): array
    {
        return ['cost_before_cents' => 'integer', 'cost_after_cents' => 'integer', 'payload' => 'array', 'credited_cents' => 'integer', 'debited_cents' => 'integer', 'paid_cents' => 'integer', 'bonus_cents' => 'integer', 'bonus_rate_bps' => 'integer', 'balance_before_cents' => 'integer', 'balance_after_cents' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El movimiento digital no se modifica.'));
        static::deleting(fn () => throw new LogicException('El historial digital no se elimina.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EntelDigitalAccount::class, 'entel_digital_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
