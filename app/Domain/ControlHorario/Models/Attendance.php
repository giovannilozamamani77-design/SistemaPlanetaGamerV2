<?php

namespace App\Domain\ControlHorario\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_pin_at' => 'immutable_datetime'];
    }

    public function scheduledWorkday(): BelongsTo
    {
        return $this->belongsTo(ScheduledWorkday::class);
    }
}
