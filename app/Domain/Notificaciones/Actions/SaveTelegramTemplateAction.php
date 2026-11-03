<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Data\TelegramEventCatalog;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramMessageTemplate;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use App\Domain\Notificaciones\Services\TelegramTemplateRenderer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveTelegramTemplateAction
{
    public function execute(User $actor, array $input): TelegramMessageTemplate
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        $v = Validator::make($input, ['module' => ['required', 'string'], 'event_code' => ['required', 'string'], 'body' => ['required', 'string', 'max:1200'], 'image_mode' => ['required', 'in:ORIGINAL,CUSTOM,NONE'], 'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:256'], 'expected_revision' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->validate();
        app(TelegramEventCatalog::class)->event($v['module'], $v['event_code']);
        TelegramTemplateRenderer::validateBody($v['body']);
        $image = null;
        if (isset($v['image'])) {
            $bytes = file_get_contents($v['image']->getRealPath());
            $info = @getimagesizefromstring($bytes);
            if (! $info || strlen($bytes) > 262144 || ! in_array($info['mime'], ['image/jpeg', 'image/png'], true) || max($info[0], $info[1]) > 4096) {
                throw ValidationException::withMessages(['image' => 'Usa PNG o JPEG de hasta 256 KB y 4096 píxeles.']);
            }
            $image = ['filename' => $info['mime'] === 'image/png' ? 'plantilla.png' : 'plantilla.jpg', 'mime' => $info['mime'], 'content' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        $hash = hash_hmac('sha256', json_encode([$v['module'], $v['event_code'], $v['body'], $v['image_mode'], (int) $v['expected_revision'], $image['sha256'] ?? null], JSON_THROW_ON_ERROR), app('encrypter')->getKey());

        return DB::transaction(function () use ($actor, $v, $image, $hash): TelegramMessageTemplate {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(TelegramSettingsQuery::class)->authorize($actor);
            if ($old = TelegramConfigurationChange::where('request_key', $v['request_key'])->first()) {
                if ((int) $old->actor_id !== $u->id) {
                    throw new AuthorizationException;
                }
                if ($old->kind !== 'MESSAGE_TEMPLATE' || ! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['body' => 'Esta solicitud ya se utilizó con otros datos.']);
                }

                return TelegramMessageTemplate::findOrFail($old->source_id);
            }
            $t = TelegramMessageTemplate::where('module', $v['module'])->where('event_code', $v['event_code'])->lockForUpdate()->first();
            if (($t?->revision ?? 0) !== (int) $v['expected_revision']) {
                throw ValidationException::withMessages(['body' => 'La plantilla cambió. Abre nuevamente el editor.']);
            }
            $before = $t ? $this->snapshot($t) : null;
            $image = $v['image_mode'] === 'CUSTOM' ? ($image ?? $t?->image) : null;
            if ($v['image_mode'] === 'CUSTOM' && ! $image) {
                throw ValidationException::withMessages(['image' => 'Sube la imagen personalizada antes de guardar.']);
            }
            $t ??= new TelegramMessageTemplate(['module' => $v['module'], 'event_code' => $v['event_code']]);
            $t->fill(['body' => $v['body'], 'image_mode' => $v['image_mode'], 'image' => $image, 'revision' => (int) $v['expected_revision'] + 1, 'updated_by' => $u->id])->save();
            TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'MESSAGE_TEMPLATE', 'source_id' => $t->id, 'snapshot' => ['before' => $before, 'after' => $this->snapshot($t)], 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);

            return $t;
        }, 3);
    }

    private function snapshot(TelegramMessageTemplate $t): array
    {
        return ['module' => $t->module, 'event_code' => $t->event_code, 'revision' => $t->revision, 'body_sha256' => hash('sha256', $t->body), 'image_mode' => $t->image_mode, 'image_sha256' => $t->image['sha256'] ?? null];
    }
}
