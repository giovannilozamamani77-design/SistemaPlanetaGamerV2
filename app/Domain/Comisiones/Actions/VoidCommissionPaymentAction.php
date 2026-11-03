<?php

namespace App\Domain\Comisiones\Actions;

use App\Domain\Comisiones\Models\CommissionPayment;
use App\Domain\Comisiones\Models\CommissionPaymentReversal;
use App\Domain\Comisiones\Queries\AdminCommissionsQuery;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Domain\Notificaciones\Actions\RecordAccessoryTelegramNoticeAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VoidCommissionPaymentAction
{
    public function execute(User $actor, array $data): CommissionPaymentReversal
    {
        app(AdminCommissionsQuery::class)->authorize($actor);
        if (is_string($data['reason'] ?? null)) {
            $data['reason'] = trim($data['reason']);
        }
        $v = Validator::make($data, ['payment_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:300'], 'request_key' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']])->validate();
        $subject = CommissionPayment::findOrFail($v['payment_id']);
        $hash = hash('sha256', json_encode([(int) $v['payment_id'], $v['reason']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $subject, $hash): CommissionPaymentReversal {
            User::whereKey($subject->user_id)->lockForUpdate()->firstOrFail();
            $payment = CommissionPayment::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            app(AdminCommissionsQuery::class)->authorize($actor);
            if ($old = CommissionPaymentReversal::where('request_key', $v['request_key'])->first()) {
                if ($old->voided_by_user_id !== $actor->id || $old->payment_id !== $payment->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['reason' => 'Este envío ya anuló un pago con otros datos. Revisa el historial.']);
                }

                return $old;
            }
            if ($payment->reversal()->exists()) {
                throw ValidationException::withMessages(['reason' => 'Este pago ya está anulado. Su importe ya volvió al pendiente.']);
            }
            $balance = app(CommissionPaymentQuery::class)->balance($actor, $payment->user_id);

            $reversal = CommissionPaymentReversal::create(['payment_id' => $payment->id, 'voided_by_user_id' => $actor->id, 'voided_by_name' => $actor->fresh()->name, 'reason' => $v['reason'], 'amount_cents' => $payment->amount_cents, 'earned_cents' => $balance['earned_cents'], 'before_paid_cents' => $balance['paid_cents'], 'before_pending_cents' => $balance['pending_cents'], 'after_pending_cents' => $balance['pending_cents'] + $payment->amount_cents, 'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
            app(RecordAccessoryTelegramNoticeAction::class)->reversal($reversal->id);

            return $reversal;
        }, 3);
    }
}
