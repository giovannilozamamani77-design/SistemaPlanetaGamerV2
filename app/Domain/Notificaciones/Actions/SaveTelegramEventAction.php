<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Data\TelegramEventCatalog;
use App\Domain\Notificaciones\Models\TelegramBotSetting;
use App\Domain\Notificaciones\Models\TelegramConfigurationChange;
use App\Domain\Notificaciones\Models\TelegramDestination;
use App\Domain\Notificaciones\Models\TelegramEventSetting;
use App\Domain\Notificaciones\Queries\TelegramSettingsQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveTelegramEventAction
{
    public function execute(User $actor, array $data): TelegramEventSetting
    {
        app(TelegramSettingsQuery::class)->authorize($actor);
        $v = Validator::make($data, [
            'module' => 'required|string', 'event_code' => 'required|string|max:60', 'prepared' => 'required|boolean',
            'destination_ids' => 'present|array|max:100', 'destination_ids.*' => 'required|integer|min:1|distinct',
            'destination_revisions' => 'present|array|max:100', 'destination_revisions.*' => 'required|integer|min:1',
            'expected_revision' => 'required|integer|min:0', 'bot_revision' => 'required|integer|min:1', 'request_key' => 'required|uuid',
            'is_enabled' => 'prohibited', 'enabled' => 'prohibited',
        ])->validate();
        app(TelegramEventCatalog::class)->event($v['module'], $v['event_code']);
        $v['prepared'] = (bool) $v['prepared'];
        $v['destination_ids'] = array_map('intval', $v['destination_ids']);
        sort($v['destination_ids']);
        if ($v['prepared'] && ! $v['destination_ids']) {
            throw ValidationException::withMessages(['destination_ids' => 'Selecciona al menos un destino para preparar este evento.']);
        }
        $revisions = [];
        foreach ($v['destination_ids'] as $id) {
            if (! isset($v['destination_revisions'][$id])) {
                throw ValidationException::withMessages(['destination_ids' => 'Abre nuevamente el formulario para revisar los destinos.']);
            }
            $revisions[$id] = (int) $v['destination_revisions'][$id];
        }
        $v['destination_revisions'] = $revisions;
        $v['expected_revision'] = (int) $v['expected_revision'];
        $v['bot_revision'] = (int) $v['bot_revision'];
        $hash = hash_hmac('sha256', json_encode($v, JSON_THROW_ON_ERROR), app('encrypter')->getKey());

        return DB::transaction(function () use ($actor, $v, $hash): TelegramEventSetting {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(TelegramSettingsQuery::class)->authorize($actor);
            if ($old = TelegramConfigurationChange::where('request_key', $v['request_key'])->first()) {
                if ((int) $old->actor_id !== $u->id) {
                    throw new AuthorizationException;
                }
                if ($old->kind !== 'EVENT_SETTING' || ! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['event_code' => 'Solicitud ya utilizada con otros datos. Abre nuevamente la configuración.']);
                }

                return TelegramEventSetting::findOrFail($old->source_id);
            }
            $bot = TelegramBotSetting::select(['id', 'module', 'revision'])->where('module', $v['module'])->lockForUpdate()->first();
            if (! $bot || (int) $bot->revision !== $v['bot_revision']) {
                throw ValidationException::withMessages(['event_code' => 'El bot cambió. Configúralo primero o abre nuevamente este formulario.']);
            }
            $destinations = TelegramDestination::whereIn('id', $v['destination_ids'])->orderBy('id')->get();
            User::whereIn('id', $destinations->pluck('responsible_id'))->orderBy('id')->lockForUpdate()->get();
            $destinations = TelegramDestination::with('responsible.roles')->whereIn('id', $v['destination_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($destinations->count() !== count($v['destination_ids'])) {
                throw ValidationException::withMessages(['destination_ids' => 'Algún destino ya no está disponible.']);
            }
            foreach ($destinations as $d) {
                if ((int) $d->bot_id !== $bot->id || ! $d->responsible?->is_active || ! $d->responsible->hasAnyRole(['administrador', 'encargado']) || (int) $d->revision !== $v['destination_revisions'][$d->id]) {
                    throw ValidationException::withMessages(['destination_ids' => 'Los destinos cambiaron o ya no están autorizados para este bot. Abre nuevamente la configuración.']);
                }
            }
            $setting = TelegramEventSetting::where('bot_id', $bot->id)->where('event_code', $v['event_code'])->lockForUpdate()->first();
            if ((int) ($setting?->revision ?? 0) !== $v['expected_revision']) {
                throw ValidationException::withMessages(['event_code' => 'Este evento cambió. Abre nuevamente el formulario.']);
            }
            $before = $setting ? $this->snapshot($setting) : null;
            $setting ??= new TelegramEventSetting(['bot_id' => $bot->id, 'event_code' => $v['event_code']]);
            $setting->fill(['prepared' => $v['prepared'], 'revision' => $v['expected_revision'] + 1, 'bot_revision' => $bot->revision, 'template_version' => TelegramEventCatalog::TEMPLATE_VERSION, 'updated_by' => $u->id])->save();
            $setting->destinations()->sync(collect($v['destination_revisions'])->map(fn ($rev) => ['destination_revision' => $rev])->all());
            TelegramConfigurationChange::create(['actor_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'EVENT_SETTING', 'source_id' => $setting->id, 'snapshot' => ['before' => $before, 'after' => $this->snapshot($setting->fresh())], 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);

            return $setting->fresh('destinations');
        }, 3);
    }

    private function snapshot(TelegramEventSetting $setting): array
    {
        return $setting->only(['bot_id', 'event_code', 'prepared', 'revision', 'bot_revision', 'template_version']) + ['destination_revisions' => $setting->destinations->mapWithKeys(fn ($d) => [$d->id => (int) $d->pivot->destination_revision])->all()];
    }
}
