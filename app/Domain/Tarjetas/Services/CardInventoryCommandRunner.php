<?php

namespace App\Domain\Tarjetas\Services;

use App\Domain\Notificaciones\Actions\RecordCardTelegramNoticeAction;
use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Models\CardInventoryCommand;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CardInventoryCommandRunner
{
    public function __construct(private CardInventoryAuthorization $authorization) {}

    public function execute(User $actor, string $ability, string $action, string $key, array $payload, callable $operation): array
    {
        $this->authorization->authorize($actor, $ability);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $ability, $action, $key, $payload, $hash, $operation): array {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $user = $this->authorization->authorize($actor, $ability);
            if ($old = CardInventoryCommand::where('request_key', $key)->first()) {
                if ($old->user_id !== $user->id) {
                    throw new AuthorizationException;
                }
                if ($old->action !== $action || ! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_key' => 'Este envío ya se registró con otros datos. Vuelve a abrir el formulario.']);
                }

                return $old->result;
            }
            $store = CardStore::firstOrCreate(['code' => 'TIENDA']);
            $store = CardStore::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $user = $this->authorization->authorize($actor, $ability);
            $lastMovementId = CardInventoryMovement::where('card_store_id', $store->id)->max('id') ?? 0;
            $operationSessionId = $store->session_control_started_at ? CardStoreSession::where('card_store_id', $store->id)->where('active_slot', 1)->value('id') : null;
            $revisionBeforeExpiry = $store->revision;
            app(CardStoreReserveService::class)->releaseDue($store, $user);
            if ($store->session_control_started_at && in_array($action, ['SALE', 'ENTEL_RECHARGE', 'PURCHASE', 'PURCHASE_BATCH', 'ADMIN_DELIVERY', 'DELIVERY_BATCH', 'CASH_ADMIN_DEPOSIT', 'CASH_ADMIN_FUNDING'], true)) {
                $session = CardStoreSession::where('card_store_id', $store->id)->where('active_slot', 1)->first();
                $administrative = $user->hasRole('administrador') && ! in_array($action, ['SALE', 'ENTEL_RECHARGE'], true);
                if (! $administrative && (! $session || $session->user_id !== $user->id)) {
                    throw ValidationException::withMessages([in_array($action, ['ENTEL_RECHARGE', 'CASH_ADMIN_DEPOSIT', 'CASH_ADMIN_FUNDING'], true) ? 'amount' : 'lines' => 'Abre tu sesión de Tarjetas antes de operar. Otra cajera debe cerrar su sesión antes del relevo.']);
                }
            }
            $result = $operation($store, $user, ['revision_before_expiry' => $revisionBeforeExpiry]);
            if ($operationSessionId) {
                DB::table('card_inventory_movements')->where('card_store_id', $store->id)->where('id', '>', $lastMovementId)->whereNull('card_store_session_id')->update(['card_store_session_id' => $operationSessionId]);
            }
            $command = CardInventoryCommand::create(['user_id' => $user->id, 'request_key' => $key, 'action' => $action, 'payload_hash' => $hash, 'payload' => $payload, 'result' => $result, 'created_at' => now()]);

            if (RecordCardTelegramNoticeAction::supports($action)) {
                app(RecordCardTelegramNoticeAction::class)->execute($command->id);
            }

            return $result;
        }, 3);
    }
}
