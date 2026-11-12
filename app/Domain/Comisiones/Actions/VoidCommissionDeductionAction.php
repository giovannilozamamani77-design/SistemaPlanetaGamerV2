<?php

namespace App\Domain\Comisiones\Actions;

use App\Domain\Comisiones\Models\CommissionDeduction;
use App\Domain\Comisiones\Models\CommissionDeductionReversal;
use App\Domain\Comisiones\Queries\CommissionDeductionQuery;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VoidCommissionDeductionAction
{
    public function execute(User $actor, array $data): CommissionDeductionReversal
    {
        app(CommissionDeductionQuery::class)->authorize($actor);
        if (is_string($data['reason'] ?? null)) {
            $data['reason'] = trim($data['reason']);
        }
        $v = Validator::make($data, ['deduction_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500'], 'request_key' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']])->validate();
        $subject = CommissionDeduction::findOrFail($v['deduction_id']);
        $hash = hash('sha256', json_encode([(int) $v['deduction_id'], $v['reason']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $subject, $hash): CommissionDeductionReversal {
            User::whereKey($subject->user_id)->lockForUpdate()->firstOrFail();
            $deduction = CommissionDeduction::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            app(CommissionDeductionQuery::class)->authorize($actor);
            if ($old = CommissionDeductionReversal::where('request_key', $v['request_key'])->first()) {
                if ($old->voided_by_user_id !== $actor->id || $old->deduction_id !== $deduction->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['reason' => 'Este envío ya anuló un descuento con otros datos. Revisa el historial.']);
                }

                return $old;
            }
            if ($deduction->reversal()->exists()) {
                throw ValidationException::withMessages(['reason' => 'Este descuento ya fue anulado. Su importe ya volvió al pendiente.']);
            }
            $balance = app(CommissionPaymentQuery::class)->balance($actor, $deduction->user_id);

            return CommissionDeductionReversal::create([
                'deduction_id' => $deduction->id, 'voided_by_user_id' => $actor->id, 'voided_by_name' => $actor->fresh()->name,
                'reason' => $v['reason'], 'amount_cents' => $deduction->amount_cents,
                'before_pending_cents' => $balance['pending_cents'], 'after_pending_cents' => $balance['pending_cents'] + $deduction->amount_cents,
                'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now(),
            ]);
        }, 3);
    }
}
