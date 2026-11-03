<?php

namespace App\Domain\Prestamos\Actions;

use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Models\LoanObjectExpense;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Domain\Prestamos\Services\LoanCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RegisterLoanObjectExpenseAction
{
    public function execute(User $actor, int $loanId, array $data): LoanObjectExpense
    {
        app(LoanAuthorization::class)->authorize($actor, 'manageLost');
        app(LoanQuery::class)->loans($actor)->whereKey($loanId)->firstOrFail();
        $v = Validator::make($data, ['amount' => ['required'], 'description' => ['required', 'string', 'max:500'], 'payment_confirmed' => ['required', 'accepted'], 'request_key' => ['required', 'uuid']])->validate();
        $amount = LoanInput::money($v['amount'], 'amount');
        $payload = ['loan_id' => $loanId, 'amount_cents' => $amount, 'description' => trim($v['description'])];
        $result = app(LoanCommandRunner::class)->execute($actor, 'manageLost', 'OBJECT_EXPENSE', $v['request_key'], $payload, function ($fund, $user) use ($payload, $v): array {
            $loan = app(LoanQuery::class)->loans($user)->whereKey($payload['loan_id'])->lockForUpdate()->firstOrFail();
            if ($loan->displayStatus() !== 'PERDIDA_TOTAL') {
                throw ValidationException::withMessages(['amount' => 'Solo se registran gastos para objetos en pérdida total que aún no se vendieron.']);
            }
            if (! $fund->initialized_at || $fund->balance_cents < $payload['amount_cents']) {
                throw ValidationException::withMessages(['amount' => 'Saldo insuficiente en Yape Préstamos. Registra un aporte real antes de pagar la reparación.']);
            }
            $summary = ['description' => $payload['description'], 'amount_cents' => $payload['amount_cents'], 'source' => 'YAPE', 'registered_by' => $user->name, 'registered_at' => now()->timezone('America/La_Paz')->format('d/m/Y H:i')];
            $event = LoanObjectExpense::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'amount_cents' => $payload['amount_cents'], 'summary' => $summary, 'created_at' => now()]);
            LoanMovement::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'kind' => 'OBJECT_EXPENSE', 'delta_cents' => -$payload['amount_cents'], 'before_cents' => $fund->balance_cents, 'after_cents' => $fund->balance_cents - $payload['amount_cents'], 'reference' => 'Gasto objeto '.$loan->id, 'created_at' => now()]);
            $fund->update(['balance_cents' => $fund->balance_cents - $payload['amount_cents'], 'revision' => $fund->revision + 1]);
            Loan::whereKey($loan->id)->update(['status' => 'PERDIDA_TOTAL']);

            return ['id' => $event->id];
        });

        return LoanObjectExpense::findOrFail($result['id']);
    }
}
