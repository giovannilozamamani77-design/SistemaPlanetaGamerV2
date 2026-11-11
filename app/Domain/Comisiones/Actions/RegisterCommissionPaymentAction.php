<?php

namespace App\Domain\Comisiones\Actions;

use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Comisiones\Models\CommissionPayment;
use App\Domain\Comisiones\Queries\AdminCommissionsQuery;
use App\Domain\Comisiones\Queries\CommissionDeductionQuery;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Domain\Notificaciones\Actions\RecordAccessoryTelegramNoticeAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RegisterCommissionPaymentAction
{
    public function execute(User $actor, array $data): CommissionPayment
    {
        app(AdminCommissionsQuery::class)->authorize($actor);
        $v = Validator::make($data, ['user_id' => ['required', 'integer', 'min:1'], 'amount' => ['required'], 'expected_earned_cents' => ['required', 'integer', 'min:0'], 'expected_paid_cents' => ['required', 'integer', 'min:0'], 'expected_deducted_cents' => ['sometimes', 'integer', 'min:0'], 'expected_reversal_count' => ['sometimes', 'integer', 'min:0'], 'request_key' => ['required', 'uuid'], 'delivered' => ['required', 'accepted'], 'reference' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:300']])->validate();
        $amount = PricingInput::money($v['amount'], 'amount');
        $reference = trim($v['reference'] ?? '') ?: null;
        $note = trim($v['note'] ?? '') ?: null;
        $hash = hash('sha256', json_encode([(int) $v['user_id'], $amount, $reference, $note, 'ADMIN_EXTERNAL'], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $amount, $reference, $note, $hash): CommissionPayment {
            $target = User::whereKey($v['user_id'])->lockForUpdate()->firstOrFail();
            app(AdminCommissionsQuery::class)->authorize($actor);
            if ($old = CommissionPayment::where('request_key', $v['request_key'])->first()) {
                if ($old->registered_by_user_id !== $actor->id || $old->user_id !== $target->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['amount' => 'Este envío ya registró un pago con otros datos. Revisa el historial.']);
                }

                return $old;
            }
            $balance = app(CommissionPaymentQuery::class)->balance($actor, $target->id);
            if (app(CommissionDeductionQuery::class)->total($actor, $target->id) !== (int) ($v['expected_deducted_cents'] ?? 0) || $balance['earned_cents'] !== (int) $v['expected_earned_cents'] || $balance['paid_cents'] !== (int) $v['expected_paid_cents'] || app(CommissionPaymentQuery::class)->reversalCount($actor, $target->id) !== (int) ($v['expected_reversal_count'] ?? 0)) {
                throw ValidationException::withMessages(['amount' => 'El saldo cambió. Cierra la ventana y revisa el pendiente antes de registrar otro pago.']);
            }
            if ($amount > $balance['pending_cents']) {
                throw ValidationException::withMessages(['amount' => 'El pago supera las comisiones pendientes.']);
            }

            $payment = CommissionPayment::create(['user_id' => $target->id, 'payee_name' => $target->name, 'registered_by_user_id' => $actor->id, 'registered_by_name' => $actor->fresh()->name, 'source' => 'ADMIN_EXTERNAL', 'amount_cents' => $amount, 'earned_cents' => $balance['earned_cents'], 'before_paid_cents' => $balance['paid_cents'], 'before_pending_cents' => $balance['pending_cents'], 'after_pending_cents' => $balance['pending_cents'] - $amount, 'reference' => $reference, 'note' => $note, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            app(RecordAccessoryTelegramNoticeAction::class)->payment($payment->id);

            return $payment;
        }, 3);
    }
}
