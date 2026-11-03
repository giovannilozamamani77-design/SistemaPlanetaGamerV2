<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Accesorios\Models\AccessoryCashDeposit;
use App\Domain\Accesorios\Models\AccessoryCashDepositReversal;
use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Comisiones\Models\CommissionPayment;
use App\Domain\Comisiones\Models\CommissionPaymentReversal;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Services\AccessoryTelegramMessage;
use App\Domain\Notificaciones\Services\AccessoryTelegramPhoto;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal adapter for confirmed Accesorios operations, under their source transaction. */
class RecordAccessoryTelegramNoticeAction
{
    public function __construct(private QueueTelegramNoticeAction $queue, private AccessoryTelegramMessage $messages, private AccessoryTelegramPhoto $photos) {}

    public function sale(int $saleId): TelegramDispatch
    {
        $this->requireTransaction();
        $sale = AccessorySale::with('lines')->findOrFail($saleId);
        if (! $sale->lines->contains(fn ($line) => $line->kind === 'PHONE')) {
            throw new LogicException('Solo las ventas con teléfonos generan este aviso Telegram.');
        }

        return $this->record('AC_VENTA_TELEFONO', 'sale:'.$saleId, fn () => $this->messages->sale($sale));
    }

    public function payment(int $paymentId): TelegramDispatch
    {
        $this->requireTransaction();
        $payment = CommissionPayment::findOrFail($paymentId);

        return $this->record('AC_COMISION_PAGO', 'payment:'.$paymentId, fn () => $this->messages->payment($payment));
    }

    public function reversal(int $reversalId): TelegramDispatch
    {
        $this->requireTransaction();
        $reversal = CommissionPaymentReversal::with('payment')->findOrFail($reversalId);

        return $this->record('AC_COMISION_ANULACION', 'payment-void:'.$reversalId, fn () => $this->messages->reversal($reversal));
    }

    public function deposit(int $depositId): TelegramDispatch
    {
        $this->requireTransaction();
        $deposit = AccessoryCashDeposit::with('movement')->findOrFail($depositId);

        return $this->record('AC_EFECTIVO_DEPOSITO', 'deposit:'.$depositId, fn () => $this->messages->deposit($deposit));
    }

    public function depositReversal(int $reversalId): TelegramDispatch
    {
        $this->requireTransaction();
        $reversal = AccessoryCashDepositReversal::with(['deposit', 'movement'])->findOrFail($reversalId);

        return $this->record('AC_EFECTIVO_ANULACION', 'deposit-void:'.$reversalId, fn () => $this->messages->depositReversal($reversal));
    }

    private function record(string $code, string $reference, callable $render): TelegramDispatch
    {
        if ($old = TelegramDispatch::where('module', 'ACCESORIOS')->where('event_code', $code)->where('source_reference', $reference)->first()) {
            return $old;
        }
        $message = $render();

        return $this->queue->execute('ACCESORIOS', $code, $reference, $message, $this->photos->snapshot($message));
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La intención Telegram se registra junto con la operación confirmada de Accesorios.');
        }
    }
}
