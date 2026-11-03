<?php

namespace App\Domain\Notificaciones\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TelegramEventSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['prepared' => 'boolean', 'revision' => 'integer', 'bot_revision' => 'integer', 'template_version' => 'integer'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBotSetting::class, 'bot_id');
    }

    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(TelegramDestination::class, 'telegram_event_destinations', 'event_setting_id', 'destination_id')->withPivot('destination_revision');
    }
}
