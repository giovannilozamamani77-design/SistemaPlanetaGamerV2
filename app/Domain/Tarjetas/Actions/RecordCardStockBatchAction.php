<?php

namespace App\Domain\Tarjetas\Actions;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Calculators\CardStockBatchCalculator;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Services\CardInventoryCommandRunner;
use App\Domain\Tarjetas\Services\CardStockEntryRecorder;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordCardStockBatchAction
{
    public function purchase(User $actor, array $data): array
    {
        return $this->execute($actor, $data, false);
    }

    public function delivery(User $actor, array $data): array
    {
        return $this->execute($actor, $data, true);
    }

    private function execute(User $actor, array $data, bool $delivery): array
    {
        $ability = $delivery ? 'receive' : 'purchase';
        app(CardInventoryAuthorization::class)->authorize($actor, $ability);
        $v = Validator::make($data, ['company' => ['required', Rule::in(['TIGO', 'VIVA', 'ENTEL'])], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.product_id' => ['required', 'integer', 'distinct'], 'lines.*.quantity' => ['nullable'], 'lines.*.unit_price' => ['nullable'], 'remember_prices' => ['sometimes', 'boolean'], 'price_revisions' => ['sometimes', 'array'], 'price_revisions.*' => ['integer', 'min:0'], 'reference' => ['nullable', 'string', 'max:200'], 'request_key' => ['required', 'uuid']])->validate();
        $totals = app(CardStockBatchCalculator::class)->normalize($v['lines'], $delivery);
        $remember = ! $delivery && (bool) ($v['remember_prices'] ?? false);
        $payload = ['company' => $v['company'], 'lines' => $totals['lines'], 'reference' => trim($v['reference'] ?? '')];

        if ($remember) {
            $revisions = array_map('intval', $v['price_revisions'] ?? []);
            ksort($revisions);
            $payload['remember_prices'] = true;
            $payload['price_revisions'] = $revisions;
        }

        return app(CardInventoryCommandRunner::class)->execute($actor, $ability, $delivery ? 'DELIVERY_BATCH' : 'PURCHASE_BATCH', $v['request_key'], $payload, function (CardStore $store, User $user) use ($payload, $totals, $delivery): array {
            if (! $store->initialized_at) {
                throw ValidationException::withMessages(['lines' => 'Administración debe registrar primero el inventario y el efectivo iniciales.']);
            }
            if (! $delivery && $store->cash_balance_cents < $totals['total_cents']) {
                throw ValidationException::withMessages(['lines' => CardStockBatchCalculator::NO_CASH]);
            }
            $products = CardProduct::whereIn('id', array_column($payload['lines'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($payload['lines'] as $line) {
                $p = $products->get($line['product_id']);
                if (! $p || ! $p->is_active || $p->company !== $payload['company']) {
                    throw ValidationException::withMessages(['lines' => 'Los cortes deben estar activos y pertenecer a la empresa seleccionada.']);
                }
            }
            $priceChanges = [];
            if ($payload['remember_prices'] ?? false) {
                foreach ($payload['lines'] as $line) {
                    $p = $products[$line['product_id']];
                    if ($p->purchase_price_cents === $line['unit_price_cents']) {
                        continue;
                    }
                    if (($payload['price_revisions'][$p->id] ?? null) !== $p->revision) {
                        throw ValidationException::withMessages(['reference' => 'El precio de referencia cambió. Vuelve a revisar la compra antes de actualizarlo.']);
                    }
                    $priceChanges[] = ['product_id' => $p->id, 'before' => $p->purchase_price_cents, 'after' => $line['unit_price_cents']];
                }
            }
            $ids = [];
            foreach ($payload['lines'] as $line) {
                $ids[] = app(CardStockEntryRecorder::class)->execute($store, $user, $products[$line['product_id']], $delivery ? 'ADMIN_DELIVERY' : 'PURCHASE', $line['quantity'], $line['unit_price_cents'], $payload['reference'])->id;
            }

            foreach ($priceChanges as $change) {
                $p = $products[$change['product_id']];
                $p->update(['purchase_price_cents' => $change['after'], 'revision' => $p->revision + 1]);
            }

            return ['price_changes' => $priceChanges, 'movement_ids' => $ids, 'total_quantity' => $totals['total_quantity'], 'total_cents' => $totals['total_cents']];
        });
    }
}
