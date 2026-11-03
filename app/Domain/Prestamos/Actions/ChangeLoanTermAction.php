<?php

namespace App\Domain\Prestamos\Actions;

use App\Domain\Notificaciones\Actions\RecordLoanTelegramNoticeAction;
use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Calculators\LoanTermCalculator;
use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanTermChange;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Domain\Prestamos\Services\LoanCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChangeLoanTermAction
{
    public function execute(User $actor, int $loanId, array $data): LoanTermChange
    {
        app(LoanAuthorization::class)->authorize($actor, 'renew');
        app(LoanQuery::class)->loans($actor)->whereKey($loanId)->firstOrFail();
        $v = Validator::make($data, ['kind' => ['required', Rule::in(['RENEW', 'EXTEND'])], 'days' => ['required', 'integer', Rule::in([15, 30])], 'periods' => ['required', 'integer', 'between:1,4'], 'expected_revision' => ['required', 'integer', 'min:0'], 'expected_paid_cents' => ['nullable', 'integer', 'min:0'], 'payment_confirmed' => ['required', 'accepted'], 'reference' => ['nullable', 'string', 'max:120'], 'request_key' => ['required', 'uuid']])->validate();
        $payload = ['loan_id' => $loanId, 'kind' => $v['kind'], 'days' => (int) $v['days'], 'periods' => (int) $v['periods'], 'expected_revision' => (int) $v['expected_revision'], 'reference' => trim($v['reference'] ?? '')];
        if (isset($v['expected_paid_cents'])) {
            $payload['expected_paid_cents'] = (int) $v['expected_paid_cents'];
        }
        $result = app(LoanCommandRunner::class)->execute($actor, 'renew', 'TERM_CHANGE', $v['request_key'], $payload, function ($fund, $user) use ($payload, $v): array {
            $loan = app(LoanQuery::class)->loans($user)->whereKey($payload['loan_id'])->lockForUpdate()->firstOrFail();
            $calc = app(LoanTermCalculator::class);
            $current = $calc->current($loan);
            if ($current['revision'] !== $payload['expected_revision']) {
                throw ValidationException::withMessages(['expected_revision' => 'Este préstamo cambió. Cierra y vuelve a abrir el formulario para revisar el cobro actual.']);
            }
            $quote = $calc->quote($loan, $payload['kind'], $payload['days'], $payload['periods']);
            if ((isset($payload['expected_paid_cents']) && $payload['expected_paid_cents'] !== $quote['paid_cents']) || ($quote['late_fee_cents'] > 0 && ! isset($payload['expected_paid_cents']))) {
                throw ValidationException::withMessages(['payment_confirmed' => 'El cobro cambió. Vuelve a abrir el formulario y revisa el interés y la multa antes de confirmar.']);
            }
            if (! $fund->initialized_at || $fund->balance_cents + $quote['paid_cents'] > LoanInput::MAX_CENTS) {
                throw ValidationException::withMessages(['payment_confirmed' => 'El fondo no está inicializado o excedería el límite permitido.']);
            }
            $summary = $quote + ['reference' => $payload['reference'], 'registered_by' => $user->name, 'registered_at' => now()->timezone('America/La_Paz')->format('d/m/Y H:i'), 'borrower' => $loan->borrower_snapshot, 'object' => $loan->object_snapshot];
            $event = LoanTermChange::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'previous_revision' => $current['revision'], 'kind' => $payload['kind'], 'summary' => $summary, 'created_at' => now()]);
            LoanMovement::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'kind' => 'TERM_PAYMENT', 'delta_cents' => $quote['paid_cents'], 'before_cents' => $fund->balance_cents, 'after_cents' => $fund->balance_cents + $quote['paid_cents'], 'reference' => $payload['reference'] ?: null, 'created_at' => now()]);
            $fund->update(['balance_cents' => $fund->balance_cents + $quote['paid_cents'], 'revision' => $fund->revision + 1]);
            // Only the current-date projection changes; original agreement and receipts remain immutable.
            Loan::whereKey($loan->id)->update(['due_date' => $quote['due_date']]);

            app(RecordLoanTelegramNoticeAction::class)->renewal($event->id);

            return ['id' => $event->id];
        });

        return LoanTermChange::findOrFail($result['id']);
    }
}
