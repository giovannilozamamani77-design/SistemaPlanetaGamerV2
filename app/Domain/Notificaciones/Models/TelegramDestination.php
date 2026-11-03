<?php

namespace App\Domain\Notificaciones\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramDestination extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'revision' => 'integer', 'bot_id' => 'integer', 'responsible_id' => 'integer'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBotSetting::class, 'bot_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }
}
