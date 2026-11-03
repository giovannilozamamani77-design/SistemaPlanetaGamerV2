<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Services\CashBoxTelegramMessage;
use App\Domain\Notificaciones\Services\CashBoxTelegramPhoto;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal writer called only after a newly recorded opening/closing, under its financial transaction. */
class RecordCashBoxTelegramNoticeAction
{
    public function __construct(private QueueTelegramNoticeAction $queue, private CashBoxTelegramMessage $messages) {}

    public function opening(int $sessionId): TelegramDispatch
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La intención Telegram se registra junto con la apertura.');
        }
        $session = CashBoxSession::findOrFail($sessionId);
        if ($session->status !== 'ABIERTA') {
            throw new LogicException('Solo una apertura recién confirmada produce este aviso.');
        }

        if ($old = TelegramDispatch::where('module', 'CAJAS')->where('event_code', 'CJ_APERTURA')->where('source_reference', 'opening:'.$sessionId)->first()) {
            return $old;
        }
        $message = $this->messages->opening($session);
        $photos = app(CashBoxTelegramPhoto::class);

        return $this->queue->execute('CAJAS', 'CJ_APERTURA', 'opening:'.$sessionId, $message, $photos->snapshot($session->box, $photos->caption($message)));
    }

    public function closing(int $closeId): TelegramDispatch
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La intención Telegram se registra junto con el cierre.');
        }
        $close = CashBoxClose::findOrFail($closeId);
        if ($close->workflow_version !== 3 || $close->cancelled_at) {
            throw new LogicException('Solo el cierre vigente confirmado produce este aviso.');
        }

        if ($old = TelegramDispatch::where('module', 'CAJAS')->where('event_code', 'CJ_CIERRE')->where('source_reference', 'close:'.$closeId)->first()) {
            return $old;
        }
        $message = $this->messages->closing($close);
        $photos = app(CashBoxTelegramPhoto::class);

        return $this->queue->execute('CAJAS', 'CJ_CIERRE', 'close:'.$closeId, $message, $photos->snapshot($close->box, $photos->caption($message)));
    }
}
