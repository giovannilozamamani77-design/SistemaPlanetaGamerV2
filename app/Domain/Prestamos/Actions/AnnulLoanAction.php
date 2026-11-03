<?php

namespace App\Domain\Prestamos\Actions;

use App\Domain\Prestamos\Authorization\LoanAuthorization;
use App\Domain\Prestamos\Data\LoanInput;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanAnnulment;
use App\Domain\Prestamos\Models\LoanMovement;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Domain\Prestamos\Services\LoanCommandRunner;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AnnulLoanAction
{
    public function execute(User $actor, int $loanId, array $data): LoanAnnulment
    {
        app(LoanAuthorization::class)->authorize($actor, 'annul');
        app(LoanQuery::class)->loans($actor)->whereKey($loanId)->firstOrFail();
        $v = Validator::make($data, ['request_key' => ['required', 'uuid'], 'capital_returned' => ['required', 'accepted'], 'reason' => ['nullable', 'string', 'max:500']])->validate();
        $payload = ['loan_id' => $loanId, 'reason' => trim($v['reason'] ?? '')];
        $result = app(LoanCommandRunner::class)->execute($actor, 'annul', 'ANNULMENT', $v['request_key'], $payload, function ($fund, $user) use ($payload, $v): array {
            $loan = app(LoanQuery::class)->loans($user)->whereKey($payload['loan_id'])->lockForUpdate()->firstOrFail();
            if ($loan->displayStatus() === 'PERDIDA_TOTAL' || $loan->status !== 'ACTIVO' || $loan->latestTermChange || $loan->settlement || $loan->annulment) {
                throw ValidationException::withMessages(['capital_returned' => 'Este préstamo tiene pagos, renovaciones o un estado final. Requiere una corrección administrativa específica; no se puede restablecer el capital otra vez.']);
            }
            if (! $fund->initialized_at || $fund->balance_cents + $loan->capital_cents > LoanInput::MAX_CENTS) {
                throw ValidationException::withMessages(['capital_returned' => 'El fondo no está inicializado o excedería el límite permitido.']);
            }
            $summary = ['capital_cents' => $loan->capital_cents, 'previous_status' => $loan->displayStatus(), 'status' => 'ANULADO', 'reason' => $payload['reason'], 'capital_returned' => true, 'registered_by' => $user->name, 'registered_at' => now()->timezone('America/La_Paz')->format('d/m/Y H:i'), 'borrower' => $loan->borrower_snapshot, 'object' => $loan->object_snapshot];
            $event = LoanAnnulment::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'request_key' => $v['request_key'], 'summary' => $summary, 'created_at' => now()]);
            LoanMovement::create(['loan_id' => $loan->id, 'user_id' => $user->id, 'kind' => 'ANNULMENT', 'delta_cents' => $loan->capital_cents, 'before_cents' => $fund->balance_cents, 'after_cents' => $fund->balance_cents + $loan->capital_cents, 'reference' => 'Anulación préstamo '.$loan->id, 'created_at' => now()]);
            $fund->update(['balance_cents' => $fund->balance_cents + $loan->capital_cents, 'revision' => $fund->revision + 1]);
            Loan::whereKey($loan->id)->update(['status' => 'ANULADO']);

            return ['id' => $event->id];
        });

        return LoanAnnulment::findOrFail($result['id']);
    }
}
