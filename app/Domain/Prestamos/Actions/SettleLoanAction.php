<?php

namespace App\Domain\Prestamos\Actions;

use App\Domain\Notificaciones\Actions\RecordLoanTelegramNoticeAction;
use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Calculators\LoanSettlementCalculator;
use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanSettlement;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Domain\Prestamos\Services\LoanCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SettleLoanAction
{
    public function execute(User $actor, int $loanId, array $data): LoanSettlement
    {
        app(LoanAuthorization::class)->authorize($actor, 'settle');
        app(LoanQuery::class)->loans($actor)->whereKey($loanId)->firstOrFail();
        $v = Validator::make($data, ['request_key' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0'], 'expected_paid_cents' => ['required', 'integer', 'min:0'], 'payment_confirmed' => ['required', 'accepted'], 'delivery_confirmed' => ['required', 'accepted'], 'reference' => ['nullable', 'string', 'max:120']])->validate();
        $payload = ['loan_id' => $loanId, 'expected_revision' => (int) $v['expected_revision'], 'expected_paid_cents' => (int) $v['expected_paid_cents'], 'reference' => trim($v['reference'] ?? '')];
        $result = app(LoanCommandRunner::class)->execute($actor, 'settle', 'SETTLEMENT', $v['request_key'], $payload, function ($fund, $user) use ($payload, $v): array {
            $loan = app(LoanQuery::class)->loans($user)->whereKey($payload['loan_id'])->lockForUpdate()->firstOrFail();
            $quote = app(LoanSettlementCalculator::class)->quote($loan);
            if ($quote['revision'] !== $payload['expected_revision'] || $quote['paid_cents'] !== $payload['expected_paid_cents']) {
                throw ValidationException::withMessages(['expected_revision' => 'El préstamo cambió. Vuelve a abrir el formulario para revisar el total.']);
            }
            if (! $fund->initialized_at || $fund->balance_cents + $quote['paid_cents'] > LoanInput::MAX_CENTS) {
                throw ValidationException::withMessages(['payment_confirmed' => 'El fondo no está inicializado o excedería el límite permitido.']);
            }
            $summary = $quote + ['borrower' => $loan->borrower_snapshot, 'object' => $loan->object_snapshot, 'reference' => $payload['reference'], 'registered_by' => $user->name, 'registered_at' => now()->timezone('America/La_Paz')->format('d/m/Y H:i'), 'status' => 'RECOGIDO_PAGADO', 'payment_confirmed' => true, 'delivery_confirmed' => true];
            $event = LoanSettlement::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'summary' => $summary, 'created_at' => now()]);
            LoanMovement::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'kind' => 'SETTLEMENT', 'delta_cents' => $quote['paid_cents'], 'before_cents' => $fund->balance_cents, 'after_cents' => $fund->balance_cents + $quote['paid_cents'], 'reference' => $payload['reference'] ?: null, 'created_at' => now()]);
            $fund->update(['balance_cents' => $fund->balance_cents + $quote['paid_cents'], 'revision' => $fund->revision + 1]);
            Loan::whereKey($loan->id)->update(['status' => 'RECOGIDO_PAGADO']);

            app(RecordLoanTelegramNoticeAction::class)->settlement($event->id);

            return ['id' => $event->id];
        });

        return LoanSettlement::findOrFail($result['id']);
    }
}
