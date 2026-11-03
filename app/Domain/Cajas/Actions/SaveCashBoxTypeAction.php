<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Data\CashBoxAccountSettings;
use App\Domain\Cajas\Models\CashBoxType;
use App\Domain\Cajas\Queries\CashBoxTypeCatalog;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveCashBoxTypeAction
{
    public function execute(User $actor, #[\SensitiveParameter] array $data): CashBoxType
    {
        $auth = app(AdministrativeReview::class);
        $auth->authorize($actor, 'cajas.catalogo.gestionar');
        $data['code'] = is_string($data['code'] ?? null) ? strtoupper(trim($data['code'])) : $data['code'] ?? null;
        $v = Validator::make($data, [
            'code' => ['required', 'string', 'max:40', 'regex:/\A[A-Z][A-Z0-9_]*\z/'], 'name' => ['required', 'string', 'max:120'],
            'family' => ['required', Rule::in(['BCP', 'TIGO_MONEY'])], 'is_active' => ['required', 'boolean'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'components' => ['required', 'array', 'min:1', 'max:50'], 'components.*' => ['required', 'array:code,name,category,reference,required'],
            'components.*.code' => ['required', 'string', 'max:20', 'distinct', 'regex:/\A[a-z][a-z0-9_]*\z/'],
            'components.*.name' => ['required', 'string', 'max:100'], 'components.*.category' => ['required', Rule::in(array_keys(CashBoxAccountSettings::categories()))],
            'components.*.reference' => ['nullable', 'string', 'max:120'], 'components.*.required' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'], 'request_key' => ['required', 'uuid'], 'pin' => ['sometimes', 'nullable', 'string'],
        ])->validate();
        foreach ($v['components'] as &$item) {
            $item['name'] = trim($item['name']);
            $item['reference'] = trim($item['reference'] ?? '') ?: null;
            $item['required'] = (bool) $item['required'];
        }
        unset($item);
        $byCode = array_column($v['components'], null, 'code');
        $source = $v['family'] === 'BCP' ? 'bcp' : 'yape';
        $sourceCategory = $v['family'] === 'BCP' ? 'banco' : 'billetera';
        if (($byCode['efectivo']['category'] ?? null) !== 'efectivo' || ($byCode[$source]['category'] ?? null) !== $sourceCategory
            || ! ($byCode['efectivo']['required'] ?? false) || ! ($byCode[$source]['required'] ?? false)) {
            throw ValidationException::withMessages(['components' => 'Conserva Efectivo y el origen operativo de liquidación como componentes obligatorios.']);
        }
        if (filled($v['pin'] ?? null)) {
            app(VerifyPersonalPinAction::class)->execute($actor, $v['pin']);
        }
        $values = ['code' => $v['code'], 'name' => trim($v['name']), 'family' => $v['family'], 'components' => array_values($v['components']), 'is_active' => (bool) $v['is_active']];
        $hash = hash('sha256', json_encode([$values, (int) $v['expected_revision'], $v['reason']], JSON_THROW_ON_ERROR));
        $result = DB::transaction(function () use ($actor, $v, $values, $hash, $auth): CashBoxType {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $auth->authorize($actor->fresh(), 'cajas.catalogo.gestionar');
            if ($audit = DB::table('cash_box_type_audits')->where('request_key', $v['request_key'])->first()) {
                if ($audit->actor_user_id !== $actor->id) {
                    throw new AuthorizationException;
                }
                if ($audit->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['reason' => 'La solicitud ya contiene otros datos.']);
                }

                return CashBoxType::findOrFail($audit->cash_box_type_id);
            }
            $type = CashBoxType::where('code', $v['code'])->lockForUpdate()->first();
            if (($type?->revision ?? 0) !== (int) $v['expected_revision'] || ($type && $type->family !== $v['family'])) {
                throw ValidationException::withMessages(['reason' => 'La plantilla cambió o intentas cambiar su circuito financiero. Reabre el formulario.']);
            }
            $before = $type?->toArray();
            if ($type) {
                $type->update($values + ['revision' => $type->revision + 1]);
            } else {
                $type = CashBoxType::create($values + ['revision' => 1]);
            }
            DB::table('cash_box_type_audits')->insert(['cash_box_type_id' => $type->id, 'actor_user_id' => $actor->id, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null, 'after' => json_encode($type->toArray(), JSON_THROW_ON_ERROR), 'reason' => $v['reason'], 'created_at' => now()]);

            return $type;
        });
        app(CashBoxTypeCatalog::class)->refresh();

        return $result;
    }
}
