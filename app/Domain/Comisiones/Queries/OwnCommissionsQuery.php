<?php

namespace App\Domain\Comisiones\Queries;

use App\Domain\Comisiones\Models\CommissionEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class OwnCommissionsQuery
{
    private function owned(User $actor): Builder
    {
        $user = User::findOrFail($actor->id);
        if (! $user->is_active) {
            throw new AuthorizationException;
        }
        Gate::forUser($user)->authorize('perfil.ver');

        return CommissionEntry::query()->join('accessory_sale_lines as commission_line', 'commission_line.id', '=', 'commission_entries.sale_line_id')->where('commission_entries.user_id', $user->id);
    }

    public function history(User $actor): Builder
    {
        return $this->owned($actor)->select(['commission_entries.id', 'commission_entries.created_at', 'commission_entries.amount_cents', 'commission_line.sale_id', 'commission_line.kind', 'commission_line.product_name', 'commission_line.quantity', 'commission_line.total_cents']);
    }

    public function overview(User $actor): array
    {
        $row = $this->owned($actor)->toBase()->selectRaw("COALESCE(SUM(commission_entries.amount_cents),0) AS total, COALESCE(SUM(CASE WHEN commission_line.kind='ACCESSORY' THEN commission_entries.amount_cents ELSE 0 END),0) AS accessories, COALESCE(SUM(CASE WHEN commission_line.kind='PHONE' THEN commission_entries.amount_cents ELSE 0 END),0) AS phones")->first();

        $paid = (int) app(CommissionPaymentQuery::class)->history($actor, $actor->id)->whereNull('reversal.id')->sum('commission_payments.amount_cents');

        $deducted = app(CommissionDeductionQuery::class)->total($actor, $actor->id);

        return ['deducted_cents' => $deducted, 'paid_cents' => $paid, 'pending_cents' => (int) $row->total - $paid - $deducted, 'total_cents' => (int) $row->total, 'accessory_cents' => (int) $row->accessories, 'phone_cents' => (int) $row->phones];
    }
}
