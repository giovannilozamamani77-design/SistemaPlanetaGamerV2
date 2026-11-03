<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Domain\Cajas\Models\CashBoxType;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class CreateCashBoxAction
{
    public function __construct(private AdministrativeReview $authorization, private VerifyPersonalPinAction $pin) {}

    /** @param array<string,mixed> $data */
    public function execute(User $actor, #[SensitiveParameter] array $data): CashBox
    {
        $this->authorization->authorize($actor, 'cajas.catalogo.gestionar');
        if (is_string($data['code'] ?? null)) {
            $data['code'] = mb_strtoupper(trim($data['code']));
        }
        foreach (['name', 'reason'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]);
            }
        }
        $v = Validator::make($data, [
            'code' => ['required', 'string', 'max:40', 'regex:/\A[A-Z0-9][A-Z0-9_-]*\z/'],
            'name' => ['required', 'string', 'max:120'], 'type' => ['required', Rule::exists('cash_box_types', 'code')->where('is_active', true)],
            'base_capital' => ['required', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'],
            'is_active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'pin' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9]{4}\z/'], 'request_key' => ['required', 'uuid'],
        ])->validate();
        if (filled($v['pin'] ?? null)) {
            $this->pin->execute($actor, $v['pin']);
        }
        $values = ['code' => $v['code'], 'name' => $v['name'], 'type' => $v['type'], 'base_capital_cents' => Bolivianos::cents($v['base_capital']), 'is_active' => (bool) $v['is_active']];
        $hash = hash('sha256', json_encode([$values, $v['reason']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $values, $hash): CashBox {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, 'cajas.catalogo.gestionar');
            if ($audit = CashBoxAudit::where('request_key', $v['request_key'])->first()) {
                if ($audit->actor_user_id !== $actor->id) {
                    throw new AuthorizationException;
                }
                if ($audit->action !== 'created' || $audit->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['request_key' => 'La solicitud ya fue utilizada con otros datos. Abre nuevamente el formulario.']);
                }

                return CashBox::findOrFail($audit->cash_box_id);
            }
            Validator::make($values, ['code' => [Rule::unique('cash_boxes', 'code')]])->validate();
            $type = CashBoxType::where('code', $v['type'])->lockForUpdate()->firstOrFail();
            if (! $type->is_active) {
                throw ValidationException::withMessages(['type' => 'El tipo de caja está inactivo.']);
            }
            $box = CashBox::create($values + ['revision' => 1, 'created_by' => $actor->id]);
            foreach ($type->components as $position => $component) {
                DB::table('cash_box_accounts')->insert(['cash_box_id' => $box->id, 'code' => $component['code'], 'name' => $component['name'], 'category' => $component['category'], 'reference' => $component['reference'] ?? null, 'position' => $position, 'is_active' => true]);
            }
            CashBoxAudit::create(['cash_box_id' => $box->id, 'actor_user_id' => $actor->id, 'request_key' => $v['request_key'],
                'action' => 'created', 'payload_hash' => $hash, 'before' => null, 'after' => $values, 'reason' => $v['reason'], 'created_at' => now()]);

            return $box;
        });
    }
}
