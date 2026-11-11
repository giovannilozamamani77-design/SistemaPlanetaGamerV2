<?php

namespace App\Domain\Comisiones\Queries;

use App\Domain\Comisiones\Models\CommissionDeduction;
use App\Domain\Comisiones\Models\CommissionEntry;
use App\Domain\Comisiones\Models\CommissionPayment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class AdminCommissionsQuery
{
    public function canView(User $actor): bool
    {
        $u = User::findOrFail($actor->id);

        return $u->is_active && $u->hasRole('administrador') && Gate::forUser($u)->allows('usuarios.ver');
    }

    public function authorize(User $actor): void
    {
        if (! $this->canView($actor)) {
            throw new AuthorizationException;
        }
    }

    private function entries(User $actor): Builder
    {
        $this->authorize($actor);

        return CommissionEntry::query()->join('accessory_sale_lines as commission_line', 'commission_line.id', '=', 'commission_entries.sale_line_id');
    }

    private function sums(): string
    {
        return "COALESCE(SUM(commission_entries.amount_cents),0) AS total_cents, COALESCE(SUM(CASE WHEN commission_line.kind='ACCESSORY' THEN commission_entries.amount_cents ELSE 0 END),0) AS accessory_cents, COALESCE(SUM(CASE WHEN commission_line.kind='PHONE' THEN commission_entries.amount_cents ELSE 0 END),0) AS phone_cents";
    }

    public function overview(User $actor): array
    {
        $row = $this->entries($actor)->toBase()->selectRaw($this->sums())->first();

        $paid = (int) CommissionPayment::active()->sum('amount_cents');

        $deducted = (int) CommissionDeduction::sum('amount_cents');

        return ['deducted_cents' => $deducted, 'paid_cents' => $paid, 'pending_cents' => (int) $row->total_cents - $paid - $deducted, 'total_cents' => (int) $row->total_cents, 'accessory_cents' => (int) $row->accessory_cents, 'phone_cents' => (int) $row->phone_cents];
    }

    public function cashiers(User $actor): Builder
    {
        $totals = $this->entries($actor)->selectRaw('commission_entries.user_id, '.$this->sums())->groupBy('commission_entries.user_id');

        $payments = CommissionPayment::active()->selectRaw('user_id, SUM(amount_cents) AS paid_cents')->groupBy('user_id');

        $deductions = CommissionDeduction::selectRaw('user_id, SUM(amount_cents) AS deducted_cents')->groupBy('user_id');

        return User::query()->leftJoinSub($deductions, 'deductions', 'deductions.user_id', '=', 'users.id')->leftJoinSub($totals, 'earned', 'earned.user_id', '=', 'users.id')->leftJoinSub($payments, 'payments', 'payments.user_id', '=', 'users.id')->where(fn (Builder $q) => $q->whereHas('roles', fn (Builder $role) => $role->whereIn('name', ['cajera', 'encargado'])->where('guard_name', 'web'))->orWhereNotNull('earned.user_id'))
            ->select(['users.id', 'users.name', 'users.is_active'])->selectRaw('COALESCE(payments.paid_cents,0) AS paid_cents, COALESCE(deductions.deducted_cents,0) AS deducted_cents, COALESCE(earned.total_cents,0)-COALESCE(payments.paid_cents,0)-COALESCE(deductions.deducted_cents,0) AS pending_cents, COALESCE(earned.total_cents,0) AS total_cents, COALESCE(earned.accessory_cents,0) AS accessory_cents, COALESCE(earned.phone_cents,0) AS phone_cents');
    }

    public function history(User $actor, int $userId): Builder
    {
        return $this->entries($actor)->where('commission_entries.user_id', $userId)->select(['commission_entries.id', 'commission_entries.created_at', 'commission_entries.amount_cents', 'commission_line.sale_id', 'commission_line.kind', 'commission_line.product_name', 'commission_line.quantity', 'commission_line.total_cents']);
    }
}
