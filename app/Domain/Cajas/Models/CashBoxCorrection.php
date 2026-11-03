<?php

namespace App\Domain\Cajas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashBoxCorrection extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CashBoxOperation::class, 'cash_box_operation_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashBoxSession::class, 'cash_box_session_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function historyEntries(): array
    {
        if (is_array($this->history)) {
            return $this->history;
        }
        if (! $this->exists) {
            return [];
        }
        // Legacy rows contain only the last persisted request and decision.
        $history = [['kind' => 'SOLICITADA', 'status' => 'PENDIENTE', 'reason' => $this->reason, 'actor_id' => $this->session->user_id, 'actor_name' => $this->session->user->name, 'at' => $this->created_at->toIso8601String(), 'legacy' => true]];
        if ($this->decided_at) {
            $history[] = ['kind' => 'DECISION', 'status' => $this->status === 'PENDIENTE' ? 'RESPUESTA PREVIA' : $this->status, 'reason' => $this->decision_reason, 'actor_id' => $this->decided_by, 'actor_name' => $this->reviewer?->name, 'at' => $this->decided_at->toIso8601String(), 'legacy' => true];
        }

        return $history;
    }

    public function appendHistory(User $actor, string $kind, string $status, string $reason): void
    {
        $this->history = [...$this->historyEntries(), ['kind' => $kind, 'status' => $status, 'reason' => $reason, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'at' => now()->toIso8601String()]];
    }

    protected function casts(): array
    {
        return ['response_version' => 'integer', 'viewed_response_version' => 'integer', 'response_read_at' => 'immutable_datetime', 'history' => 'array', 'created_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }
}
