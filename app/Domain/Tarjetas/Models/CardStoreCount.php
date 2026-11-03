<?php

namespace App\Domain\Tarjetas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CardStoreCount extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'lines' => 'array', 'cash_expected_cents' => 'integer', 'cash_counted_cents' => 'integer', 'cash_reserved_expected_cents' => 'integer', 'cash_reserved_counted_cents' => 'integer', 'cash_available_counted_cents' => 'integer', 'shortage_cents' => 'integer', 'store_revision' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CardStoreSession::class, 'card_store_session_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El conteo confirmado no se reescribe.'));
        static::deleting(fn () => throw new LogicException('El conteo confirmado no se elimina.'));
    }
}
