<?php

namespace App\Domain\Liquidaciones\Actions;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Queries\CashBoxTypeCatalog;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SetLiquidationRulesAction
{
    public function execute(User $actor, int $boxId, array $data): CashBoxConfiguration
    {
        app(AdministrativeReview::class)->authorize($actor, 'cajas.catalogo.gestionar');
        $v = Validator::make($data, ['source' => ['required', 'string', 'max:20'], 'source_reference' => ['nullable', 'string', 'max:120'],
            'destination_key' => ['sometimes', 'required', 'string', 'max:36'], 'morning' => ['required', 'boolean'], 'afternoon' => ['required', 'boolean'], 'expected_revision' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:2000', 'regex:/\S/u'], 'request_key' => ['required', 'uuid']])->validate();
        $v['source_reference'] = trim($v['source_reference'] ?? '') ?: null;
        $v['morning'] = (bool) $v['morning'];
        $v['afternoon'] = (bool) $v['afternoon'];
        $hash = hash('sha256', json_encode([$boxId, $v['source'], $v['source_reference'], $v['destination_key'] ?? null, $v['morning'], $v['afternoon'], (int) $v['expected_revision'], $v['reason']], JSON_THROW_ON_ERROR));
        $result = DB::transaction(function () use ($actor, $boxId, $v, $hash): CashBoxConfiguration {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(AdministrativeReview::class)->authorize($actor, 'cajas.catalogo.gestionar');
            $box = CashBox::whereKey($boxId)->lockForUpdate()->firstOrFail();
            if ($prior = CashBoxConfiguration::where('request_key', $v['request_key'])->first()) {
                if ($prior->created_by !== $actor->id || $prior->cash_box_id !== $boxId || $prior->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['reason' => 'Esta solicitud ya se utilizó con otros datos.']);
                }

                return $prior;
            }
            if ($box->revision !== (int) $v['expected_revision']) {
                throw ValidationException::withMessages(['reason' => 'La configuración cambió. Vuelve a abrir el formulario.']);
            }
            $current = $box->configurations()->latest('id')->first();
            if (! $current) {
                throw ValidationException::withMessages(['source' => 'Primero configura las cuentas y turnos de esta caja.']);
            }
            $destinationKey = $v['destination_key'] ?? $current->settlement_destination_key;
            if (isset($v['destination_key']) && ! DB::table('cash_box_commission_accounts')->where('account_key', $destinationKey)->where('is_demo', false)->exists()) {
                throw ValidationException::withMessages(['destination_key' => 'Selecciona una cuenta de destino real configurada.']);
            }
            $account = $current->accounts[$v['source']] ?? null;
            $category = $account['category'] ?? ($v['source'] === 'bcp' ? 'banco' : ($v['source'] === 'yape' ? 'billetera' : ''));
            if (! $account || ! ($account['is_active'] ?? true) || ! in_array($category, ['banco', 'billetera', 'tigo_money'], true)) {
                throw ValidationException::withMessages(['source' => 'Selecciona una cuenta bancaria o billetera activa de esta caja.']);
            }
            $accounts = $current->accounts;
            $accounts[$v['source']]['reference'] = $v['source_reference'];
            $shifts = $current->shifts;
            $shifts['manana']['liquidates'] = $v['morning'];
            $shifts['tarde']['liquidates'] = $v['afternoon'];
            $new = $current->replicate();
            $new->fill(['accounts' => $accounts, 'shifts' => $shifts, 'settlement_source' => $v['source'], 'settlement_destination_key' => $destinationKey, 'created_by' => $actor->id,
                'reason' => $v['reason'], 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            $new->save();
            DB::table('cash_box_accounts')->where('cash_box_id', $boxId)->where('code', $v['source'])->update(['reference' => $v['source_reference']]);
            $box->update(['revision' => $box->revision + 1]);
            CashBoxAudit::create(['cash_box_id' => $boxId, 'actor_user_id' => $actor->id, 'request_key' => $v['request_key'], 'action' => 'liquidation_rules',
                'payload_hash' => $hash, 'before' => ['configuration_id' => $current->id], 'after' => ['configuration_id' => $new->id, 'source' => $v['source'], 'destination_key' => $destinationKey],
                'reason' => $v['reason'], 'created_at' => now()]);

            return $new;
        });
        app(CashBoxTypeCatalog::class)->refresh();

        return $result;
    }
}
