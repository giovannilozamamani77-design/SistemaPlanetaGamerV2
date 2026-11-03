<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\PricingChange;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Domain\Accesorios\Queries\PricingQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SavePricingSettingsAction
{
    public function executeDollar(User $actor, array $data): PricingChange
    {
        return $this->executePartial($actor, $data, ['adjustment']);
    }

    public function executeRules(User $actor, array $data): PricingChange
    {
        $fields = ['new_phone_auto_update'];
        foreach (PricingInput::PREFIXES as $prefix) {
            $fields[] = $prefix.'_mode';
            $fields[] = $prefix.'_percent';
        }

        return $this->executePartial($actor, $data, $fields);
    }

    private function executePartial(User $actor, array $data, array $fields): PricingChange
    {
        return DB::transaction(function () use ($actor, $data, $fields): PricingChange {
            $query = app(PricingQuery::class);
            $query->authorize($actor);
            Validator::make($data, ['request_key' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0']])->validate();
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $base = $query->form($actor);
            $keys = array_merge($fields, ['expected_revision', 'request_key']);
            $values = array_replace($base, array_intersect_key($data, array_flip($keys)), ['exchange_mode' => 'REFERENCE', 'auto_refresh' => true]);
            $event = $this->execute($actor, $values);
            app(RefreshAutomaticPhonePricesAction::class)->execute($actor);

            return $event;
        }, 3);
    }

    public function execute(User $actor, array $data): PricingChange
    {
        app(PricingQuery::class)->authorize($actor);
        $v = Validator::make($data, ['request_key' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0']])->validate();
        $values = PricingInput::configuration($data);
        $hash = hash('sha256', json_encode(['values' => $values, 'revision' => (int) $v['expected_revision']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $values, $hash): PricingChange {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $user = app(PricingQuery::class)->authorize($actor);
            if ($old = PricingChange::where('request_key', $v['request_key'])->first()) {
                if ($old->user_id !== $user->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['expected_revision' => 'Este envío ya se confirmó con otros datos. Vuelve a abrir la configuración.']);
                }

                return $old;
            }
            PricingSettings::firstOrCreate(['code' => 'GENERAL'], PricingInput::defaults());
            $settings = PricingSettings::where('code', 'GENERAL')->lockForUpdate()->firstOrFail();
            if ($settings->revision !== (int) $v['expected_revision']) {
                throw ValidationException::withMessages(['expected_revision' => 'La configuración cambió. Ciérrala y vuelve a abrirla para revisar los valores actuales.']);
            }
            $before = $settings->only(['exchange_mode', 'physical_rate_micros', 'adjustment_micros', 'auto_refresh', 'rules', 'revision']);
            $settings->update($values + ['revision' => $settings->revision + 1, 'updated_by' => $user->id, 'updated_at' => now()]);

            return PricingChange::create(['settings_id' => $settings->id, 'user_id' => $user->id, 'actor_name' => $user->name, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'previous_revision' => $before['revision'], 'before' => $before, 'after' => $settings->fresh()->only(array_keys($before)), 'created_at' => now()]);
        }, 3);
    }
}
