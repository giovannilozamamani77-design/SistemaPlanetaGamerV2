<?php

namespace App\Domain\Notificaciones\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramDispatch extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['message', 'media', 'payload_hash', 'event_key'];

    protected function casts(): array
    {
        return ['message' => 'encrypted', 'media' => 'encrypted:array'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(TelegramDelivery::class, 'dispatch_id');
    }
}
