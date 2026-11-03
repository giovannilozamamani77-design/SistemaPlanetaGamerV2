<?php

namespace App\Domain\Notificaciones\Actions;

use App\Domain\Notificaciones\Models\TelegramDispatch;
use App\Domain\Notificaciones\Services\CardTelegramMessage;
use App\Domain\Notificaciones\Services\CardTelegramPhoto;
use App\Domain\Tarjetas\Models\CardInventoryCommand;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal adapter for scoped facts, called after the new command is persisted under the store transaction. */
class RecordCardTelegramNoticeAction
{
    public function __construct(private QueueTelegramNoticeAction $queue, private CardTelegramMessage $messages, private CardTelegramPhoto $photos) {}

    public static function supports(string $action): bool
    {
        return in_array($action, ['COUNT_OPEN', 'COUNT_CLOSE', 'PURCHASE', 'PURCHASE_BATCH', 'CASH_ADMIN_DEPOSIT'], true);
    }

    public function execute(int $commandId): TelegramDispatch
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La intención Telegram se registra junto con el hecho de Tarjetas.');
        }
        $command = CardInventoryCommand::findOrFail($commandId);
        if (! self::supports($command->action)) {
            throw new LogicException('Este hecho de Tarjetas no está conectado a Telegram.');
        }
        $actor = User::findOrFail($command->user_id)->name;
        if (in_array($command->action, ['COUNT_OPEN', 'COUNT_CLOSE'], true)) {
            $count = CardStoreCount::findOrFail($command->result['count_id']);

            return $this->record($count->kind === 'OPEN' ? 'TJ_APERTURA' : 'TJ_CIERRE', 'count:'.$count->id, fn () => $this->messages->count($count, $actor));
        }
        if ($command->action === 'CASH_ADMIN_DEPOSIT') {
            $movement = CardInventoryMovement::findOrFail($command->result['movement_id']);

            return $this->record('TJ_EFECTIVO', 'deposit:'.$movement->id, fn () => $this->messages->deposit($movement, $actor));
        }
        $ids = $command->result['movement_ids'] ?? [$command->result['movement_id']];
        $movements = CardInventoryMovement::whereIn('id', $ids)->orderBy('id')->get();

        return $this->record('TJ_COMPRA', 'purchase:'.$command->id, fn () => $this->messages->purchase($command, $movements, $actor), $movements->first()->product_snapshot['company']);
    }

    private function record(string $code, string $reference, callable $render, ?string $company = null): TelegramDispatch
    {
        if ($old = TelegramDispatch::where('module', 'TARJETAS')->where('event_code', $code)->where('source_reference', $reference)->first()) {
            return $old;
        }
        $message = $render();

        return $this->queue->execute('TARJETAS', $code, $reference, $message, $this->photos->snapshot($message, $company));
    }
}
