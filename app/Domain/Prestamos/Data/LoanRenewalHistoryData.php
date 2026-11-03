<?php

namespace App\Domain\Prestamos\Data;

use App\Domain\Prestamos\Models\LoanTermChange;
use App\Support\Bolivianos;
use Carbon\CarbonImmutable;

class LoanRenewalHistoryData
{
    public function present(LoanTermChange $event): array
    {
        $saved = $event->summary;
        $from = CarbonImmutable::parse($saved['previous_due_date'], 'America/La_Paz')->addDay()->format('d/m/Y');
        $to = CarbonImmutable::parse($saved['due_date'], 'America/La_Paz')->format('d/m/Y');
        $fee = (int) ($saved['late_fee_cents'] ?? 0);
        $interest = (int) ($saved['interest_paid_cents'] ?? ($saved['paid_cents'] - $fee));

        return ['paid_at' => $event->created_at->timezone('America/La_Paz')->format('d/m/Y H:i'), 'period_from' => $from, 'period_to' => $to, 'period' => $from.' → '.$to, 'duration' => ($event->kind === 'EXTEND' ? $saved['added_days'] : $saved['days']).' días'.(($saved['periods'] ?? 1) > 1 ? ' × '.$saved['periods'].' periodos' : ''), 'registered_by' => $saved['registered_by'], 'paid_cents' => $saved['paid_cents'], 'interest_paid_cents' => $interest, 'late_fee_cents' => $fee, 'payment_breakdown' => 'Interés Bs '.Bolivianos::formatCompact($interest).' · Multa Bs '.Bolivianos::formatCompact($fee)];
    }
}
