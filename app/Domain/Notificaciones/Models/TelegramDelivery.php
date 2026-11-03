<?php

namespace App\Domain\Notificaciones\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramDelivery extends Model
{
    public const LABELS = ['PENDIENTE' => 'Pendiente', 'PROCESANDO' => 'Procesando', 'ENVIADA' => 'Enviada', 'REINTENTO_PENDIENTE' => 'Reintento pendiente', 'ERROR_DEFINITIVO' => 'Error definitivo', 'INCIERTA' => 'Resultado incierto', 'OMITIDA' => 'Omitida', 'CANCELADA' => 'Cancelada'];

    protected $guarded = ['id'];

    protected $hidden = ['chat_id', 'chat_fingerprint', 'snapshot', 'claim_token'];

    protected function casts(): array
    {
        return ['chat_id' => 'encrypted', 'snapshot' => 'array', 'automatic_attempts' => 'integer', 'attempt_count' => 'integer', 'manual_pending' => 'boolean', 'next_attempt_at' => 'immutable_datetime', 'queued_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime'];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(TelegramDispatch::class, 'dispatch_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(TelegramDeliveryAttempt::class, 'delivery_id');
    }
}
