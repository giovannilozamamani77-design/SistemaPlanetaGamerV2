<?php

namespace App\Domain\Prestamos\Calculators;

use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\Loan;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class LoanTermCalculator
{
    public function current(Loan $loan): array
    {
        $last = $loan->latestTermChange;
        if (in_array($loan->status, ['RECOGIDO_PAGADO', 'ANULADO', 'VENDIDO'], true)) {
            return ['revision' => $last?->id ?? 0, 'days' => $last?->summary['days'] ?? $loan->original_days, 'due_date' => $loan->due_date->toDateString(), 'pending_cents' => 0, 'interest_cents' => 0, 'credited_cents' => 0];
        }

        return $last ? ['revision' => $last->id] + $last->summary : ['revision' => 0, 'days' => $loan->original_days, 'interest_cents' => $loan->original_interest_cents, 'credited_cents' => 0, 'pending_cents' => $loan->original_interest_cents, 'due_date' => $loan->original_due_date->toDateString(), 'rate_bps' => $loan->original_rate_bps];
    }

    public function amountToCollect(Loan $loan): ?int
    {
        return match ($loan->displayStatus()) {
            'ACTIVO' => $loan->capital_cents + $this->current($loan)['pending_cents'],
            'CON_MULTA' => $loan->capital_cents + $this->current($loan)['pending_cents'] + app(LoanLateFeeCalculator::class)->amount($loan),
            'RECOGIDO_PAGADO', 'ANULADO', 'VENDIDO' => 0,
            default => null,
        };
    }

    public function canExtend(Loan $loan): bool
    {
        return $loan->displayStatus() === 'ACTIVO' && $this->current($loan)['days'] === 15
            && ! (($loan->terms_snapshot['interest_mode'] ?? 'AUTO') === 'MANUAL' && $loan->latestTermChange === null);
    }

    public function quote(Loan $loan, string $kind, int $days, int $periods): array
    {
        if (! in_array($loan->displayStatus(), ['ACTIVO', 'CON_MULTA'], true)) {
            throw ValidationException::withMessages(['kind' => 'Este préstamo ya no admite renovación o ampliación. Desde el día 8 de atraso es pérdida total.']);
        }
        $current = $this->current($loan);
        $calculator = app(LoanCalculator::class);
        $interest = $calculator->terms($loan->capital_cents, $loan->original_rate_bps, $days, $loan->start_date->toDateString())['interest_cents'];
        if ($kind === 'EXTEND') {
            if (! $this->canExtend($loan) || $days !== 30 || $periods !== 1) {
                throw ValidationException::withMessages(['kind' => 'No se puede ampliar este periodo. Usa Renovar para elegir otro plazo con la tasa original.']);
            }
            $paid = $interest - $current['interest_cents'];
            $credited = $current['credited_cents'] + $paid;
            $added = 15;
        } else {
            $paid = $current['pending_cents'] + ($periods - 1) * $interest;
            $credited = 0;
            $added = $days * $periods;
        }
        $interestPaid = $paid;
        $fee = app(LoanLateFeeCalculator::class)->amount($loan);
        $paid += $fee;
        if ($paid < 0 || $paid > LoanInput::MAX_CENTS) {
            throw ValidationException::withMessages(['days' => 'Importe fuera del límite permitido.']);
        }

        return ['late_fee_cents' => $fee, 'interest_paid_cents' => $interestPaid, 'late_episode_due_date' => $fee > 0 ? $current['due_date'] : null, 'kind' => $kind, 'days' => $days, 'periods' => $periods, 'added_days' => $added, 'capital_cents' => $loan->capital_cents, 'rate_bps' => $loan->original_rate_bps, 'previous_due_date' => $current['due_date'], 'due_date' => CarbonImmutable::parse($current['due_date'], 'America/La_Paz')->addDays($added)->toDateString(), 'previous_pending_cents' => $current['pending_cents'], 'paid_cents' => $paid, 'interest_cents' => $interest, 'credited_cents' => $credited, 'pending_cents' => $interest - $credited];
    }
}
