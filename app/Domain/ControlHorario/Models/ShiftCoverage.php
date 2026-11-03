<?php

namespace App\Domain\ControlHorario\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftCoverage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function workday(): BelongsTo
    {
        return $this->belongsTo(ScheduledWorkday::class, 'workday_id');
    }

    public function sourceWorkday(): BelongsTo
    {
        return $this->belongsTo(ScheduledWorkday::class, 'source_workday_id');
    }

    public function creditWorkday(): BelongsTo
    {
        return $this->belongsTo(ScheduledWorkday::class, 'credit_workday_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
