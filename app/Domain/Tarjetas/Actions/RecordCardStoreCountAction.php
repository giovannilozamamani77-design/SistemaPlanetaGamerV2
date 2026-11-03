<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Incidencias\Actions\RecordTarjetasCountIncidentsAction;
use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Calculators\CardAverageCostCalculator;
use App\Domain\Tarjetas\Calculators\CardStoreCountCalculator;
use App\Domain\Tarjetas\Models\CardInventoryMovement;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Domain\Tarjetas\Models\CardStoreReserve;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Domain\Tarjetas\Services\CardStoreReserveService;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordCardStoreCountAction
{
    public function open(User $actor, array $data): CardStoreCount
    {
        return $this->execute($actor, $data, 'OPEN');
    }

    public function close(User $actor, array $data): CardStoreCount
    {
        return $this->execute($actor, $data, 'CLOSE');
    }

    private function execute(User $actor, array $data, string $kind): CardStoreCount
    {
        $ability = $kind === 'OPEN' ? 'openSession' : 'closeSession';
        app(CardInventoryAuthorization::class)->authorize($actor, $ability);
        $v = Validator::make($data, ['store_revision' => ['required', 'integer', 'min:0'], 'cash' => ['present'], 'stocks' => ['present', 'array', 'max:200'], 'reason' => ['nullable', 'string', 'max:2000'], 'request_key' => ['required', 'uuid']])->validate();
        $payload = ['kind' => $kind, 'revision' => (int) $v['store_revision'], 'cash' => $v['cash'], 'stocks' => $v['stocks'], 'reason' => trim($v['reason'] ?? '')];
        $result = app(CardInventoryCommandRunner::class)->execute($actor, $ability, 'COUNT_'.$kind, $v['request_key'], $payload, function (CardStore $store, User $user, array $context) use ($kind, $payload): array {
            if (! $store->initialized_at) {
                throw ValidationException::withMessages(['cash' => 'Administración debe registrar el inventario inicial.']);
            }
            if ($context['revision_before_expiry'] !== $payload['revision']) {
                throw ValidationException::withMessages(['cash' => 'Hubo nuevos movimientos. Vuelve a abrir el conteo para actualizar lo esperado.']);
            }
            $active = CardStoreSession::where('card_store_id', $store->id)->where('active_slot', 1)->lockForUpdate()->first();
            if ($kind === 'OPEN' && $active) {
                throw ValidationException::withMessages(['cash' => 'La tienda ya tiene una sesión abierta. No se duplicó la apertura.']);
            }
            if ($kind === 'CLOSE' && (! $active || $active->user_id !== $user->id)) {
                throw ValidationException::withMessages(['cash' => 'Solo la cajera responsable puede cerrar su sesión abierta.']);
            }
            $expected = app(CardStoreSessionQuery::class)->expected($store);
            $data = app(CardStoreCountCalculator::class)->compare($expected, $payload);
            $responsible = $kind === 'CLOSE' ? $active->user_id : CardStoreSession::where('card_store_id', $store->id)->whereNotNull('closed_at')->latest('id')->value('user_id') ?? $store->initialized_by;
            if ($data['shortage_cents'] && ! $responsible) {
                throw ValidationException::withMessages(['cash' => 'Administración debe identificar quién registró el inventario inicial antes de asignar este faltante.']);
            }
            $session = $active ?? CardStoreSession::create(['card_store_id' => $store->id, 'user_id' => $user->id, 'active_slot' => 1, 'opened_at' => now()]);
            $count = CardStoreCount::create(['card_store_id' => $store->id, 'card_store_session_id' => $session->id, 'user_id' => $user->id, 'responsible_user_id' => $responsible, 'kind' => $kind, 'store_revision' => $store->revision, 'cash_expected_cents' => $expected['cash_total_cents'], 'cash_counted_cents' => $data['cash'], 'cash_reserved_expected_cents' => $data['cash_reserved_expected'], 'cash_reserved_counted_cents' => $data['cash_reserved_counted'], 'cash_available_counted_cents' => $data['cash_available_counted'], 'shortage_cents' => $data['shortage_cents'], 'lines' => $data['lines'], 'reason' => $payload['reason'] ?: null, 'created_at' => now()]);
            $held = app(CardStoreReserveService::class)->retained($store, null, $data['cash']);
            if ($data['cash_surplus']) {
                $this->reserve($store, $count, null, $data['cash_surplus']);
                $held += $data['cash_surplus'];
            }
            $before = $store->cash_balance_cents;
            $after = $data['cash'] - $held;
            CardInventoryMovement::create(['card_store_id' => $store->id, 'card_store_session_id' => $session->id, 'user_id' => $user->id, 'kind' => 'COUNT_'.$kind.'_CASH', 'quantity' => 0, 'cash_delta_cents' => $after - $before, 'cash_before_cents' => $before, 'cash_after_cents' => $after, 'reference' => 'Conteo #'.$count->id]);
            foreach ($data['lines'] as $line) {
                $stock = CardStock::firstOrCreate(['card_store_id' => $store->id, 'card_product_id' => $line['product_id']], ['quantity' => 0]);
                $held = app(CardStoreReserveService::class)->retained($store, $line['product_id'], $line['counted']);
                $extra = max(0, $line['counted'] - $line['expected']);
                if ($extra) {
                    $this->reserve($store, $count, $line['product_id'], $extra);
                    $held += $extra;
                }$actual = $line['counted'] - $held;
                $valueBefore = $stock->cost_value_cents;
                $valueAfter = app(CardAverageCostCalculator::class)->afterCount($stock->quantity, $valueBefore, $actual);
                CardInventoryMovement::create(['cost_before_cents' => $valueBefore, 'cost_after_cents' => $valueAfter, 'card_store_id' => $store->id, 'card_store_session_id' => $session->id, 'card_product_id' => $line['product_id'], 'user_id' => $user->id, 'kind' => 'COUNT_'.$kind.'_STOCK', 'quantity' => abs($actual - $stock->quantity), 'cash_delta_cents' => 0, 'stock_before' => $stock->quantity, 'stock_after' => $actual, 'cash_before_cents' => $after, 'cash_after_cents' => $after, 'product_snapshot' => ['company' => $line['company'], 'face_value_cents' => $line['face_value_cents'], 'label' => $line['label']], 'reference' => 'Conteo #'.$count->id]);
                $stock->update(['quantity' => $actual, 'cost_value_cents' => $valueAfter, 'cost_revision' => $stock->cost_revision + 1]);
            }
            $store->update(['cash_balance_cents' => $after, 'revision' => $store->revision + 1, 'session_control_started_at' => $store->session_control_started_at ?? now()]);
            if ($kind === 'CLOSE') {
                $session->update(['active_slot' => null, 'closed_at' => now()]);
            }
            app(RecordTarjetasCountIncidentsAction::class)->execute($user, $count);

            return ['count_id' => $count->id];
        });

        return CardStoreCount::findOrFail($result['count_id']);
    }

    private function reserve(CardStore $store, CardStoreCount $count, ?int $productId, int $amount): void
    {
        CardStoreReserve::create(['card_store_id' => $store->id, 'card_store_count_id' => $count->id, 'card_product_id' => $productId, 'original_amount' => $amount, 'held_amount' => $amount, 'expires_at' => $count->kind === 'CLOSE' ? now()->addDays(5) : null, 'created_at' => now()]);
    }
}
