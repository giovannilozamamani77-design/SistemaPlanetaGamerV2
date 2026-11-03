<?php

namespace App\Domain\Tarjetas\Queries;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Calculators\CardStoreCountCalculator;
use App\Domain\Tarjetas\Calculators\CardStoreCountSummary;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStock;
use App\Domain\Tarjetas\Models\CardStore;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Domain\Tarjetas\Models\CardStoreReserve;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Models\User;
use App\Support\Bolivianos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CardStoreSessionQuery
{
    public function status(User $actor): array
    {
        app(CardInventoryAuthorization::class)->authorize($actor, 'sell');
        $store = CardStore::where('code', 'TIENDA')->first();
        $session = $store?->session_control_started_at ? CardStoreSession::with('user')->where('card_store_id', $store->id)->where('active_slot', 1)->first() : null;

        return ['session' => $session, 'is_owner' => $session?->user_id === $actor->id, 'initialized' => (bool) $store?->initialized_at];
    }

    public function expected(CardStore $store): array
    {
        $reserves = CardStoreReserve::where('card_store_id', $store->id)->whereNull('released_at')->get();
        $stocks = CardStock::where('card_store_id', $store->id)->get()->keyBy('card_product_id');
        $products = [];
        foreach (CardProduct::orderBy('company')->orderBy('face_value_cents')->get() as $p) {
            $held = (int) $reserves->where('card_product_id', $p->id)->sum('held_amount');
            $q = $stocks->get($p->id)?->quantity ?? 0;
            if (! $p->is_active && ! $q && ! $held) {
                continue;
            }$products[$p->id] = ['label' => $p->cutLabel(), 'company' => $p->company, 'face_value_cents' => $p->face_value_cents, 'available_quantity' => $q, 'reserved_quantity' => $held, 'expected_quantity' => $q + $held];
        }
        $reserved = (int) $reserves->whereNull('card_product_id')->sum('held_amount');

        return ['revision' => $store->revision, 'cash_available_cents' => $store->cash_balance_cents, 'cash_reserved_cents' => $reserved, 'cash_total_cents' => $store->cash_balance_cents + $reserved, 'products' => $products];
    }

    public function preview(User $actor, string $kind, array $data): array
    {
        app(CardInventoryAuthorization::class)->authorize($actor, $kind === 'OPEN' ? 'openSession' : 'closeSession');
        $store = CardStore::where('code', 'TIENDA')->firstOrFail();
        $e = $this->expected($store);
        try {
            $comparison = app(CardStoreCountCalculator::class)->compare($e, $data, requireSurplusReason: false);
        } catch (ValidationException) {
            $comparison = null;
        }

        return ['kind' => $kind, 'expected_cash' => $e['cash_total_cents'], 'reserved_cash' => $e['cash_reserved_cents'], 'comparison' => $comparison, 'summary' => $comparison ? app(CardStoreCountSummary::class)->make($e['cash_total_cents'], $comparison['cash'], $comparison['cash_reserved_counted'], $comparison['cash_available_counted'], $comparison['shortage_cents'], $comparison['lines']) : null];
    }

    public function history(User $actor, ?string $kind = null): Builder
    {
        app(CardInventoryAuthorization::class)->authorize($actor, 'history');

        Validator::make(['kind' => $kind], ['kind' => ['nullable', Rule::in(['OPEN', 'CLOSE'])]])->validate();

        return CardStoreCount::query()->with('user')->when($kind, fn (Builder $query) => $query->where('kind', $kind));
    }

    public function historyCashiers(User $actor): array
    {
        return User::query()->whereIn('id', $this->history($actor)->select('user_id'))->orderBy('name')->pluck('name', 'id')->all();
    }

    public function filterHistory(Builder $query, array $data): Builder
    {
        if (Validator::make($data, ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d'], 'user_id' => ['nullable', 'integer', 'min:1']])->fails()
            || (filled($data['from'] ?? null) && filled($data['until'] ?? null) && $data['from'] > $data['until'])) {
            return $query->whereRaw('1 = 0');
        }
        foreach (['from', 'until'] as $field) {
            if (filled($data[$field] ?? null)) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $data[$field], 'America/La_Paz');
                $query->where('created_at', $field === 'from' ? '>=' : '<', ($field === 'from' ? $date : $date->addDay())->utc());
            }
        }
        if (filled($data['user_id'] ?? null)) {
            $query->where('user_id', (int) $data['user_id']);
        }

        return $query;
    }

    public function differences(CardStoreCount $count): array
    {
        $stockSurplus = 0;
        foreach ($count->lines as $line) {
            $stockSurplus += max(0, $line['counted'] - $line['expected']);
        }

        return ['shortage_cents' => $count->shortage_cents, 'cash_surplus_cents' => max(0, $count->cash_counted_cents - $count->cash_expected_cents), 'stock_surplus' => $stockSurplus];
    }

    public function detail(User $actor, int $countId): array
    {
        app(CardInventoryAuthorization::class)->authorize($actor, 'history');
        $count = CardStoreCount::with('user')->findOrFail($countId);

        $calculator = app(CardStoreCountSummary::class);
        $summary = $calculator->make($count->cash_expected_cents, $count->cash_counted_cents, $count->cash_reserved_counted_cents, $count->cash_available_counted_cents, $count->shortage_cents, $count->lines);

        return ['count' => $count, 'summary' => $summary, 'detail' => $calculator->historyGroups($summary)];
    }

    public function form(User $actor, string $kind): array
    {
        app(CardInventoryAuthorization::class)->authorize($actor, $kind === 'OPEN' ? 'openSession' : 'closeSession');
        $store = CardStore::where('code', 'TIENDA')->firstOrFail();
        $e = $this->expected($store);

        return ['cash_expected_cents' => $e['cash_total_cents'], 'store_revision' => $e['revision'], 'cash' => Bolivianos::formatCompact($e['cash_total_cents']), 'stocks' => array_map(fn ($id, $p) => ['product_id' => $id, 'label' => $p['label'], 'company' => $p['company'], 'expected' => $p['expected_quantity'], 'count_in_opening' => $p['expected_quantity'] > 0, 'reserved' => $p['reserved_quantity'], 'quantity' => $p['expected_quantity']], array_keys($e['products']), array_values($e['products'])), 'reason' => null, 'request_key' => (string) Str::uuid()];
    }
}
