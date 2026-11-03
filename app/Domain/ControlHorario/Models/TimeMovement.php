<?php

namespace App\Domain\ControlHorario\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeMovement extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(TimeIncident::class, 'time_incident_id');
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime', 'delta_minutes' => 'integer'];
    }
}
