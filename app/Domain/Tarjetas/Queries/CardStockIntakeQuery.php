<?php

namespace App\Domain\Tarjetas\Queries;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Calculators\CardStockBatchCalculator;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStore;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Validation\ValidationException;

class CardStockIntakeQuery
{
    public function lines(User $user, ?string $company, bool $delivery): array
    {
        app(CardInventoryAuthorization::class)->authorize($user, $delivery ? 'receive' : 'purchase');
        if (! in_array($company, ['TIGO', 'VIVA', 'ENTEL'], true)) {
            return [];
        }

        return CardProduct::where('company', $company)->where('is_active', true)->orderBy('face_value_cents')->get()->map(fn ($p) => ['product_id' => $p->id, 'label' => $p->cutLabel(), 'quantity' => 0, 'unit_price' => $delivery ? null : Bolivianos::formatCompact($p->purchase_price_cents)])->all();
    }

    public function priceChanges(User $user, array $data): array
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'purchase');
        $normalized = app(CardStockBatchCalculator::class)->normalize((array) ($data['lines'] ?? []), false);
        $products = CardProduct::whereIn('id', array_column($normalized['lines'], 'product_id'))->get()->keyBy('id');
        $changes = [];
        foreach ($normalized['lines'] as $line) {
            $product = $products->get($line['product_id']);
            if (! $product || ! $product->is_active || $product->company !== ($data['company'] ?? null)) {
                throw ValidationException::withMessages(['lines' => 'Los cortes deben estar activos y pertenecer a la empresa seleccionada.']);
            }
            if ($product->purchase_price_cents !== $line['unit_price_cents']) {
                $changes[] = ['product_id' => $product->id, 'label' => $product->label(), 'old_cents' => $product->purchase_price_cents, 'new_cents' => $line['unit_price_cents'], 'revision' => $product->revision];
            }
        }

        return $changes;
    }

    public function cash(User $user): ?int
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'purchase');
        $s = CardStore::where('code', 'TIENDA')->first();

        return $s?->initialized_at ? $s->cash_balance_cents : null;
    }
}
