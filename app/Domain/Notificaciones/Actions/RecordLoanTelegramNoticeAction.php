<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Services\LoanTelegramMessage;
use App\Domain\Notificaciones\Services\LoanTelegramPhoto;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanSettlement;
use App\Domain\Prestamos\Models\LoanTermChange;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal adapter, only for newly persisted facts inside the financial transaction. */
class RecordLoanTelegramNoticeAction
{
    public function __construct(private QueueTelegramNoticeAction $queue, private LoanTelegramMessage $messages, private LoanTelegramPhoto $photos) {}

    public function registration(int $loanId): TelegramDispatch
    {
        $this->requireTransaction();
        $loan = Loan::findOrFail($loanId);

        return $this->record('PR_ALTA', 'loan:'.$loanId, fn () => $this->messages->registration($loan));
    }

    public function renewal(int $changeId): TelegramDispatch
    {
        $this->requireTransaction();
        $change = LoanTermChange::findOrFail($changeId);

        return $this->record('PR_RENOVACION', 'term:'.$changeId, fn () => $this->messages->renewal($change));
    }

    public function settlement(int $settlementId): TelegramDispatch
    {
        $this->requireTransaction();
        $settlement = LoanSettlement::findOrFail($settlementId);

        return $this->record('PR_PAGO_ENTREGA', 'settlement:'.$settlementId, fn () => $this->messages->settlement($settlement));
    }

    private function record(string $code, string $reference, callable $render): TelegramDispatch
    {
        if ($old = TelegramDispatch::where('module', 'PRESTAMOS')->where('event_code', $code)->where('source_reference', $reference)->first()) {
            return $old;
        }
        $message = $render();

        return $this->queue->execute('PRESTAMOS', $code, $reference, $message, $this->photos->snapshot($message));
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La intención Telegram se registra junto con el hecho de Préstamos.');
        }
    }
}
