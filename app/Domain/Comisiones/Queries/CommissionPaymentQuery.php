<?php

namespace App\Domain\Comisiones\Queries;

use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Comisiones\Models\CommissionPayment;
use App\Domain\Comisiones\Models\CommissionPaymentReversal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class CommissionPaymentQuery
{
    public function authorize(User $actor, int $userId): void
    {
        $u = User::findOrFail($actor->id);
        if (app(AdminCommissionsQuery::class)->canView($u)) {
            return;
        }
        if (! $u->is_active || $u->id !== $userId) {
            throw new AuthorizationException;
        }
        Gate::forUser($u)->authorize('perfil.ver');
    }

    public function history(User $actor, int $userId): Builder
    {
        $this->authorize($actor, $userId);

        return CommissionPayment::where('commission_payments.user_id', $userId)->leftJoin('commission_payment_reversals as reversal', 'reversal.payment_id', '=', 'commission_payments.id')->select(['commission_payments.id', 'commission_payments.user_id', 'commission_payments.registered_by_name', 'commission_payments.amount_cents', 'commission_payments.created_at', 'commission_payments.reference', 'commission_payments.note', 'commission_payments.source', 'reversal.id as reversal_id', 'reversal.reason as reversal_reason', 'reversal.voided_by_name as reversed_by_name', 'reversal.created_at as reversed_at']);
    }

    public function reversalCount(User $actor, int $userId): int
    {
        $this->authorize($actor, $userId);

        return CommissionPaymentReversal::join('commission_payments', 'commission_payments.id', '=', 'commission_payment_reversals.payment_id')->where('commission_payments.user_id', $userId)->count();
    }

    public function balance(User $actor, int $userId): array
    {
        $this->authorize($actor, $userId);
        $earned = (int) CommissionEntry::where('user_id', $userId)->sum('amount_cents');
        $paid = (int) CommissionPayment::active()->where('user_id', $userId)->sum('amount_cents');

        return ['earned_cents' => $earned, 'paid_cents' => $paid, 'pending_cents' => $earned - $paid];
    }
}
