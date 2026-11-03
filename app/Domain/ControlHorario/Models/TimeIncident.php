<?php

namespace App\Domain\ControlHorario\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeIncident extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['calculation' => 'array', 'observed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'real_minutes' => 'integer', 'nominal_minutes' => 'integer', 'pending_minutes' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scheduledWorkday(): BelongsTo
    {
        return $this->belongsTo(ScheduledWorkday::class);
    }
}
