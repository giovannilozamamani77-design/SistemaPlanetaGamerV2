<?php

namespace App\Domain\Notificaciones\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramBotSetting extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token', 'token_fingerprint'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'is_enabled' => 'boolean', 'revision' => 'integer'];
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(TelegramDestination::class, 'bot_id');
    }
}
