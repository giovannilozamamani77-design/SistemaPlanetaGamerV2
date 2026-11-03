<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\CommercialRuleChange;
use App\Domain\Accesorios\Models\CommercialRules;
use App\Domain\Accesorios\Models\PricingSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveCommercialRulesAction
{
    public function authorize(User $actor): void
    {
        $u = User::findOrFail($actor->id);
        abort_unless($u->hasRole('administrador'), 403);
        Gate::forUser($u)->authorize('configure', PricingSettings::class);
    }

    public function execute(User $actor, array $data): CommercialRules
    {
        $this->authorize($actor);
        $v = Validator::make($data, ['scope' => ['required', Rule::in(['DISCOUNTS', 'COMMISSIONS'])], 'expected_revision' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid'], 'ranges' => ['required', 'array', 'min:1', 'max:20'], 'ranges.*.from' => ['required'], 'ranges.*.amount' => ['required'], 'enabled' => ['sometimes', 'boolean']])->validate();
        $ranges = [];
        foreach (array_values($v['ranges']) as $i => $range) {
            $from = PricingInput::money($range['from'], 'ranges.'.$i.'.from', true);
            $amount = PricingInput::money($range['amount'], 'ranges.'.$i.'.amount', true);
            if (($i === 0 && $from !== 0) || ($i > 0 && $from <= $ranges[$i - 1]['from_cents'])) {
                throw ValidationException::withMessages(['ranges' => 'El primer rango empieza en 0 y los siguientes deben estar ordenados, sin repetirse.']);
            }
            $ranges[] = ['from_cents' => $from, 'amount_cents' => $amount];
        }
        $rules = ['ranges' => $ranges];
        if ($v['scope'] === 'DISCOUNTS') {
            $rules['phone_max_cents'] = PricingInput::money($data['phone_max'] ?? null, 'phone_max', true);
        } else {
            $bps = PricingInput::money($data['phone_percent'] ?? null, 'phone_percent', true);
            if ($bps > 10000) {
                throw ValidationException::withMessages(['phone_percent' => 'El porcentaje debe estar entre 0 y 100.']);
            }
            $rules += ['enabled' => $v['enabled'] ?? true, 'phone_bps' => $bps];
        }
        $hash = hash('sha256', json_encode([$v['scope'], $rules], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $rules, $hash): CommercialRules {
            $this->authorize($actor);
            CommercialRules::firstOrCreate(['scope' => $v['scope']], ['rules' => CommercialRules::defaults($v['scope']), 'revision' => 0]);
            $row = CommercialRules::where('scope', $v['scope'])->lockForUpdate()->firstOrFail();
            if ($old = CommercialRuleChange::where('request_key', $v['request_key'])->first()) {
                abort_unless($old->user_id === $actor->id, 403);
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['ranges' => 'Este envío ya se guardó con otros datos.']);
                }

                return $row;
            }
            if ($row->revision !== (int) $v['expected_revision']) {
                throw ValidationException::withMessages(['ranges' => 'La configuración cambió. Cierra y vuelve a abrir esta ventana.']);
            }
            CommercialRuleChange::create(['scope' => $v['scope'], 'user_id' => $actor->id, 'actor_name' => $actor->fresh()->name, 'before_rules' => $row->rules, 'after_rules' => $rules, 'previous_revision' => $row->revision, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            $row->update(['rules' => $rules, 'revision' => $row->revision + 1]);

            return $row;
        }, 3);
    }
}
