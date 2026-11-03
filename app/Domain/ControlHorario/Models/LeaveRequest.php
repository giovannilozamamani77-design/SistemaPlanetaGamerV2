<?php

namespace App\Domain\ControlHorario\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function monthlyLeave(): BelongsTo
    {
        return $this->belongsTo(MonthlyLeave::class);
    }

    public function scheduledWorkday(): BelongsTo
    {
        return $this->belongsTo(ScheduledWorkday::class);
    }
}
