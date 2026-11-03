<?php

namespace App\Domain\Prestamos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanMovement extends Model
{
    public $timestamps = false;

    protected $table = 'loan_movements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['delta_cents' => 'integer', 'before_cents' => 'integer', 'after_cents' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El registro confirmado no se reescribe.'));
        static::deleting(fn () => throw new \LogicException('El historial no se elimina.'));
    }
}
