<?php

namespace App\Domain\Accesorios\Actions;

use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Accesorios\Models\AccessoryCashDeposit;
use App\Domain\Accesorios\Models\AccessoryCashDepositReversal;
use App\Domain\Accesorios\Models\CommercialFund;
use App\Domain\Accesorios\Models\CommercialMovement;
use App\Domain\Accesorios\Queries\AccessoryCashDepositQuery;
use App\Domain\Notificaciones\Actions\RecordAccessoryTelegramNoticeAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VoidAccessoryCashDepositAction
{
    public function execute(User $actor, array $data): AccessoryCashDepositReversal
    {
        app(AccessoryCashDepositQuery::class)->authorize($actor, 'void');
        if (is_string($data['reason'] ?? null)) {
            $data['reason'] = trim($data['reason']);
        }
        $v = Validator::make($data, ['deposit_id' => ['required', 'integer', 'min:1'], 'expected_revision' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300'], 'confirmed' => ['required', 'accepted'], 'request_key' => ['required', 'uuid']])->validate();
        $hash = hash('sha256', json_encode([(int) $v['deposit_id'], (int) $v['expected_revision'], $v['reason']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $hash): AccessoryCashDepositReversal {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $u = app(AccessoryCashDepositQuery::class)->authorize($actor, 'void');
            if ($old = AccessoryCashDepositReversal::where('request_key', $v['request_key'])->first()) {
                if ($old->user_id !== $u->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['reason' => 'Este envío ya anuló un depósito con otros datos.']);
                }

                return $old;
            }
            $fund = CommercialFund::where('code', 'GENERAL')->lockForUpdate()->firstOrFail();
            $d = AccessoryCashDeposit::whereKey($v['deposit_id'])->lockForUpdate()->firstOrFail();
            if ($d->reversal()->exists()) {
                throw ValidationException::withMessages(['reason' => 'Este depósito ya está anulado; el importe ya volvió al efectivo.']);
            }
            if ($fund->revision !== (int) $v['expected_revision']) {
                throw ValidationException::withMessages(['reason' => 'El efectivo cambió. Vuelve a revisar la anulación.']);
            }
            $after = $fund->balance_cents + $d->amount_cents;
            if ($after > PricingInput::MAX_CENTS) {
                throw ValidationException::withMessages(['reason' => 'La restitución supera el límite del fondo.']);
            }
            $m = CommercialMovement::create(['fund_id' => $fund->id, 'user_id' => $u->id, 'actor_name' => $u->name, 'kind' => 'ADMIN_DEPOSIT_VOID', 'amount_cents' => $d->amount_cents, 'before_cents' => $fund->balance_cents, 'after_cents' => $after, 'previous_revision' => $fund->revision, 'created_at' => now()]);
            $r = AccessoryCashDepositReversal::create(['deposit_id' => $d->id, 'movement_id' => $m->id, 'user_id' => $u->id, 'actor_name' => $u->name, 'reason' => $v['reason'], 'amount_cents' => $d->amount_cents, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            $fund->update(['balance_cents' => $after, 'revision' => $fund->revision + 1]);

            app(RecordAccessoryTelegramNoticeAction::class)->depositReversal($r->id);

            return $r;
        }, 3);
    }
}
