<?php

namespace App\Domain\Prestamos\Actions;

use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Calculators\LoanRecoveryCalculator;
use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanObjectSale;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Domain\Prestamos\Services\LoanCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SellLoanObjectAction
{
    public function execute(User $actor, int $loanId, array $data): LoanObjectSale
    {
        app(LoanAuthorization::class)->authorize($actor, 'sell');
        app(LoanQuery::class)->loans($actor)->whereKey($loanId)->firstOrFail();
        $v = Validator::make($data, ['price' => ['required'], 'expected_expense_cents' => ['required', 'integer', 'min:0'], 'payment_confirmed' => ['required', 'accepted'], 'sale_notes' => ['nullable', 'string', 'max:500'], 'buyer' => ['nullable', 'string', 'max:160'], 'reference' => ['nullable', 'string', 'max:120'], 'request_key' => ['required', 'uuid']])->validate();
        $payload = ['loan_id' => $loanId, 'price_cents' => LoanInput::money($v['price'], 'price'), 'expected_expense_cents' => (int) $v['expected_expense_cents'], 'sale_notes' => trim($v['sale_notes'] ?? ''), 'buyer' => trim($v['buyer'] ?? ''), 'reference' => trim($v['reference'] ?? '')];
        $result = app(LoanCommandRunner::class)->execute($actor, 'sell', 'OBJECT_SALE', $v['request_key'], $payload, function ($fund, $user) use ($payload, $v): array {
            $loan = app(LoanQuery::class)->loans($user)->whereKey($payload['loan_id'])->lockForUpdate()->firstOrFail();
            $expenses = (int) $loan->recovery_expense_cents;
            if ($expenses !== $payload['expected_expense_cents']) {
                throw ValidationException::withMessages(['price' => 'Se registraron nuevos gastos. Abre de nuevo la venta y revisa el mínimo actualizado.']);
            }
            $quote = app(LoanRecoveryCalculator::class)->quote($loan, $expenses, $payload['price_cents']);
            if (! $fund->initialized_at || $fund->balance_cents + $quote['price_cents'] > LoanInput::MAX_CENTS) {
                throw ValidationException::withMessages(['payment_confirmed' => 'El fondo no está inicializado o excedería el límite permitido.']);
            }
            $summary = $quote + ['borrower' => $loan->borrower_snapshot, 'object' => $loan->object_snapshot, 'sale_notes' => $payload['sale_notes'], 'buyer' => $payload['buyer'], 'reference' => $payload['reference'], 'registered_by' => $user->name, 'registered_at' => now()->timezone('America/La_Paz')->format('d/m/Y H:i'), 'status' => 'VENDIDO', 'due_date' => $loan->due_date->toDateString()];
            $event = LoanObjectSale::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'summary' => $summary, 'created_at' => now()]);
            LoanMovement::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'kind' => 'OBJECT_SALE', 'delta_cents' => $quote['price_cents'], 'before_cents' => $fund->balance_cents, 'after_cents' => $fund->balance_cents + $quote['price_cents'], 'reference' => $payload['reference'] ?: null, 'created_at' => now()]);
            $fund->update(['balance_cents' => $fund->balance_cents + $quote['price_cents'], 'revision' => $fund->revision + 1]);
            Loan::whereKey($loan->id)->update(['status' => 'VENDIDO']);

            return ['id' => $event->id];
        });

        return LoanObjectSale::findOrFail($result['id']);
    }
}
