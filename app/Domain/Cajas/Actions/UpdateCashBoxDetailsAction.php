<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class UpdateCashBoxDetailsAction
{
    public function __construct(private AdministrativeReview $authorization, private VerifyPersonalPinAction $pin) {}

    /** @param array<string,mixed> $data */
    public function execute(User $actor, CashBox $box, #[SensitiveParameter] array $data): CashBox
    {
        $this->authorization->authorize($actor, 'cajas.catalogo.gestionar');
        foreach (['name', 'reason'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]);
            }
        }
        $v = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'], 'is_active' => ['required', 'boolean'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'code' => ['prohibited'], 'type' => ['prohibited'], 'base_capital' => ['prohibited'], 'base_capital_cents' => ['prohibited'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'pin' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9]{4}\z/'], 'request_key' => ['required', 'uuid'],
        ])->validate();
        if (filled($v['pin'] ?? null)) {
            $this->pin->execute($actor, $v['pin']);
        }
        $values = ['name' => $v['name'], 'is_active' => (bool) $v['is_active']];
        $hash = hash('sha256', json_encode([$box->id, $values, (int) $v['expected_revision'], $v['reason']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $box, $v, $values, $hash): CashBox {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, 'cajas.catalogo.gestionar');
            if ($audit = CashBoxAudit::where('request_key', $v['request_key'])->first()) {
                if ($audit->actor_user_id !== $actor->id) {
                    throw new AuthorizationException;
                }
                if ($audit->cash_box_id !== $box->id || $audit->action !== 'details_updated' || $audit->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['request_key' => 'La solicitud ya fue utilizada con otros datos. Abre nuevamente el formulario.']);
                }

                return CashBox::findOrFail($box->id);
            }
            $current = CashBox::whereKey($box->id)->lockForUpdate()->firstOrFail();
            if ($current->revision !== (int) $v['expected_revision']) {
                throw ValidationException::withMessages(['name' => 'Otro administrador modificó esta caja. Cierra y vuelve a abrir el formulario.']);
            }
            if (CashBoxSession::where('cash_box_id', $box->id)->whereNotNull('active_slot')->exists()) {
                throw ValidationException::withMessages(['reason' => 'La caja debe estar cerrada y liberada antes de modificarla.']);
            }
            $before = $current->only(['name', 'is_active']);
            if ($before === $values) {
                throw ValidationException::withMessages(['name' => 'No hay cambios para guardar.']);
            }
            $current->update($values + ['revision' => $current->revision + 1]);
            CashBoxAudit::create(['cash_box_id' => $current->id, 'actor_user_id' => $actor->id, 'request_key' => $v['request_key'],
                'action' => 'details_updated', 'payload_hash' => $hash, 'before' => $before, 'after' => $values, 'reason' => $v['reason'], 'created_at' => now()]);

            return $current;
        });
    }
}
