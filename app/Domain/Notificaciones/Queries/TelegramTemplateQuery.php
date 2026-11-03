<?php

namespace App\Domain\Notificaciones\Queries;

use App\Domain\Notificaciones\Data\TelegramEventCatalog;
use App\Domain\Notificaciones\Models\TelegramMessageTemplate;
use App\Domain\Notificaciones\Services\TelegramTemplateRenderer;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TelegramTemplateQuery
{
    public function form(User $actor, string $module, string $code): array
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        app(TelegramEventCatalog::class)->event($module, $code);
        $t = TelegramMessageTemplate::where('module', $module)->where('event_code', $code)->first();

        return ['module' => $module, 'event_code' => $code, 'body' => $t?->body ?? TelegramTemplateRenderer::DEFAULT_BODY, 'image_mode' => $t?->image_mode ?? 'ORIGINAL', 'expected_revision' => $t?->revision ?? 0, 'request_key' => (string) Str::uuid()];
    }

    public function testForm(User $actor, string $module, string $code): array
    {
        $form = $this->form($actor, $module, $code);
        $data = app(TelegramEventsQuery::class)->forModule($actor, $module);
        $bot = $data['bot'];
        if (! $bot) {
            throw ValidationException::withMessages(['event_code' => 'Configura primero el bot y un destino para enviar una prueba.']);
        }

        return ['bot_id' => $bot->id, 'event_code' => $code, 'expected_revision' => $bot->revision, 'expected_template_revision' => $form['expected_revision'], 'destination_revisions' => $data['destinations']->pluck('revision', 'id')->all(), 'request_key' => (string) Str::uuid(), 'confirmed' => false];
    }
}
