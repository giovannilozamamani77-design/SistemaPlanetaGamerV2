<?php

namespace App\Domain\Cajas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashBoxEarlyRequest extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashBoxSession::class, 'cash_box_session_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }
}
