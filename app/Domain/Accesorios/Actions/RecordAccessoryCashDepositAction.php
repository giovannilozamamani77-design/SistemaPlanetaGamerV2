<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryCashDeposit;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Accesorios\Queries\AccessoryCashDepositQuery;
use App\Domain\Notificaciones\Actions\RecordAccessoryTelegramNoticeAction;
use App\Domain\Tarjetas\Models\CardDepositAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordAccessoryCashDepositAction
{
    public function execute(User $actor, array $data): AccessoryCashDeposit
    {
        app(AccessoryCashDepositQuery::class)->authorize($actor);
        $v = Validator::make($data, ['account_id' => ['required', 'integer', 'min:1'], 'account_revision' => ['required', 'integer', 'min:1'], 'expected_revision' => ['required', 'integer', 'min:0'], 'amount' => ['required'], 'operation_reference' => ['nullable', 'string', 'max:200'], 'confirmed' => ['required', 'accepted'], 'request_key' => ['required', 'uuid']])->validate();
        $payload = ['account_id' => (int) $v['account_id'], 'account_revision' => (int) $v['account_revision'], 'expected_revision' => (int) $v['expected_revision'], 'amount_cents' => PricingInput::money($v['amount'], 'amount'), 'operation_reference' => trim($v['operation_reference'] ?? '')];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $payload, $hash): AccessoryCashDeposit {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(AccessoryCashDepositQuery::class)->authorize($actor);
            if ($old = AccessoryCashDeposit::where('request_key', $v['request_key'])->first()) {
                if ($old->user_id !== $u->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['amount' => 'Este envío ya se registró con otros datos. Revisa el historial.']);
                }

                return $old;
            }
            $fund = CommercialFund::where('code', 'GENERAL')->lockForUpdate()->first();
            if (! $fund || $fund->balance_cents < $payload['amount_cents']) {
                throw ValidationException::withMessages(['amount' => 'El importe supera el efectivo disponible de Accesorios.']);
            }
            if ($fund->revision !== $payload['expected_revision']) {
                throw ValidationException::withMessages(['amount' => 'El efectivo cambió. Cierra y vuelve a revisar el depósito.']);
            }
            $account = CardDepositAccount::whereKey($payload['account_id'])->lockForUpdate()->first();
            if (! $account || ! $account->is_active || $account->revision !== $payload['account_revision']) {
                throw ValidationException::withMessages(['account_id' => 'La cuenta cambió o fue desactivada. Vuelve a seleccionarla.']);
            }
            $after = $fund->balance_cents - $payload['amount_cents'];
            $m = CommercialMovement::create(['fund_id' => $fund->id, 'user_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'ADMIN_CASH_DEPOSIT', 'amount_cents' => $payload['amount_cents'], 'before_cents' => $fund->balance_cents, 'after_cents' => $after, 'previous_revision' => $fund->revision, 'created_at' => now()]);
            $d = AccessoryCashDeposit::create(['movement_id' => $m->id, 'account_id' => $account->id, 'user_id' => $u->id, 'actor_name' => $u->name, 'destination_snapshot' => $account->snapshot(), 'amount_cents' => $payload['amount_cents'], 'operation_reference' => $payload['operation_reference'] ?: null, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            $fund->update(['balance_cents' => $after, 'revision' => $fund->revision + 1]);

            app(RecordAccessoryTelegramNoticeAction::class)->deposit($d->id);

            return $d;
        }, 3);
    }
}
