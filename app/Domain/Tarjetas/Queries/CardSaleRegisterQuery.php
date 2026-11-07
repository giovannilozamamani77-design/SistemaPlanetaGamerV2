<?php

namespace App\Domain\Tarjetas\Queries;

use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Models\CardCompanySaleEntry;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardSaleRegisterEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CardSaleRegisterQuery
{
    public function entries(User $user): Builder
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'sell');
        $cards = DB::table('card_sales')->selectRaw("CAST(id AS CHAR) as id, id as source_id, 'CARD' as entry_type, user_id, created_at, mode, total_quantity, total_cents, NULL as customer_total_cents, NULL as bonus_rate_bps, (SELECT created_at FROM card_sale_cancellations WHERE card_sale_id = card_sales.id) as cancelled_at");
        $digital = DB::table('entel_recharges')->selectRaw("CAST(-id AS CHAR) as id, id as source_id, 'RECHARGE' as entry_type, user_id, created_at, kind as mode, NULL as total_quantity, amount_cents as total_cents, customer_total_cents, bonus_rate_bps, NULL as cancelled_at");

        return CardSaleRegisterEntry::query()->fromSub($cards->unionAll($digital), 'sale_register')->with(['user', 'lines']);
    }

    /** One receipt per operation, scoped to the cashier serving the currently open session. */
    public function currentSessionEntries(User $user): Builder
    {
        $query = $this->entries($user);
        $session = app(CardStoreSessionQuery::class)->status($user)['session'];
        if (! $session || $session->closed_at !== null) {
            return $query->whereRaw('1 = 0');
        }
        $cards = DB::table('card_sale_lines as line')
            ->join('card_inventory_movements as movement', 'movement.id', '=', 'line.card_inventory_movement_id')
            ->where('movement.card_store_session_id', $session->id)->select('line.card_sale_id');
        $digital = DB::table('entel_recharges as recharge')
            ->join('card_inventory_movements as movement', 'movement.id', '=', 'recharge.card_inventory_movement_id')
            ->where('movement.card_store_session_id', $session->id)->select('recharge.id');

        return $query->where('sale_register.user_id', $session->user_id)
            ->where(function (Builder $q) use ($cards, $digital): void {
                $q->where(fn (Builder $card) => $card->where('entry_type', 'CARD')->whereIn('source_id', $cards))
                    ->orWhere(fn (Builder $recharge) => $recharge->where('entry_type', 'RECHARGE')->whereIn('source_id', $digital));
            });
    }

    public function currentSessionDetail(User $user, CardSaleRegisterEntry $entry): array
    {
        $entry = $this->currentSessionEntries($user)->where('sale_register.id', $entry->id)->firstOrFail();

        return $this->detail($user, $entry);
    }

    public function companyEntries(User $user): Builder
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'sell');
        $company = DB::connection()->getQueryGrammar()->wrap('product_snapshot->company');
        $company = "COALESCE({$company}, 'SIN_EMPRESA')";
        $lines = DB::table('card_sale_lines')->select('card_sale_id')
            ->selectRaw("MIN(id) as row_id, {$company} as company, SUM(quantity) as total_quantity, SUM(total_cents) as total_cents")
            ->groupBy('card_sale_id')->groupByRaw($company);
        $cards = DB::table('card_sales as sales')->joinSub($lines, 'company_lines', 'company_lines.card_sale_id', '=', 'sales.id')
            ->selectRaw("CAST(company_lines.row_id AS CHAR) as id, sales.id as source_id, sales.id as line_sale_id, 'CARD' as entry_type, sales.user_id, sales.created_at, sales.mode, company_lines.company, company_lines.total_quantity, company_lines.total_cents, NULL as customer_total_cents, NULL as bonus_rate_bps, (SELECT created_at FROM card_sale_cancellations WHERE card_sale_id = sales.id) as cancelled_at");
        $digital = DB::table('entel_recharges')->selectRaw("CAST(-id AS CHAR) as id, id as source_id, -id as line_sale_id, 'RECHARGE' as entry_type, user_id, created_at, kind as mode, 'ENTEL' as company, NULL as total_quantity, amount_cents as total_cents, customer_total_cents, bonus_rate_bps, NULL as cancelled_at");

        return CardCompanySaleEntry::query()->fromSub($cards->unionAll($digital), 'sale_register')->with(['user', 'lines']);
    }

    public function currentTurnSummary(User $user): ?array
    {
        $session = app(CardStoreSessionQuery::class)->status($user)['session'];
        if (! $session) {
            return null;
        }
        $company = DB::connection()->getQueryGrammar()->wrap('lines.product_snapshot->company');
        $company = "COALESCE({$company}, 'SIN_EMPRESA')";
        $cards = DB::table('card_sale_lines as lines')
            ->join('card_inventory_movements as movements', 'movements.id', '=', 'lines.card_inventory_movement_id')
            ->whereNotIn('lines.card_sale_id', DB::table('card_sale_cancellations')->select('card_sale_id'))
            ->where('movements.card_store_session_id', $session->id)
            ->selectRaw("{$company} as company, SUM(lines.quantity) as quantity, SUM(lines.total_cents) as total_cents")
            ->groupByRaw($company)->get()->keyBy('company');
        $digital = DB::table('entel_recharges as recharges')
            ->join('card_inventory_movements as movements', 'movements.id', '=', 'recharges.card_inventory_movement_id')
            ->where('movements.card_store_session_id', $session->id)
            ->selectRaw('recharges.kind, SUM(recharges.amount_cents) as total_cents')
            ->groupBy('recharges.kind')->pluck('total_cents', 'kind');
        $companies = [];
        foreach (array_unique(['ENTEL', 'TIGO', 'VIVA', ...$cards->keys()->all()]) as $key) {
            $total = (int) ($cards[$key]->total_cents ?? 0);
            $credit = $key === 'ENTEL' ? (int) ($digital['CREDIT'] ?? 0) : 0;
            $packages = $key === 'ENTEL' ? (int) ($digital['PACKAGES'] ?? 0) : 0;
            $companies[$key] = ['quantity' => (int) ($cards[$key]->quantity ?? 0), 'total_cents' => $total, 'credit_cents' => $credit, 'packages_cents' => $packages, 'collected_cents' => $total + $credit + $packages];
        }

        return ['responsible' => $session->user?->name, 'companies' => $companies];
    }

    public function companyDetail(User $user, CardCompanySaleEntry $entry): array
    {
        $entry = $this->companyEntries($user)->where('sale_register.id', $entry->id)->firstOrFail();
        if ($entry->entry_type === 'RECHARGE') {
            return ['lines' => [], 'customer_total_cents' => $entry->customer_total_cents, 'bonus_rate_bps' => $entry->bonus_rate_bps];
        }

        return ['lines' => $entry->companyLines()->map(fn ($line) => ['product_snapshot' => ['label' => $line->product_snapshot['label'] ?? 'Tarjeta'], 'quantity' => $line->quantity, 'unit_price_cents' => $line->unit_price_cents, 'total_cents' => $line->total_cents])->all()];
    }

    public function filterHistory(Builder $query, array $data): Builder
    {
        if (Validator::make($data, [
            'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d'],
            'company' => ['nullable', Rule::in(['ENTEL', 'TIGO', 'VIVA'])],
            'type' => ['nullable', Rule::in(['CARD', 'CREDIT', 'PACKAGES'])],
        ])->fails() || (filled($data['from'] ?? null) && filled($data['until'] ?? null) && $data['from'] > $data['until'])) {
            return $query->whereRaw('1 = 0');
        }
        foreach (['from', 'until'] as $field) {
            if (filled($data[$field] ?? null)) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $data[$field], 'America/La_Paz');
                $boundary = ($field === 'from' ? $date : $date->addDay())->utc();
                $query->where('sale_register.created_at', $field === 'from' ? '>=' : '<', $boundary);
            }
        }
        if (in_array($data['company'] ?? null, ['ENTEL', 'TIGO', 'VIVA'], true)) {
            $company = $data['company'];
            if ($query->getModel() instanceof CardCompanySaleEntry) {
                $query->where('sale_register.company', $company);
            } else {
                $query->where(function (Builder $q) use ($company): void {
                    $q->where(fn (Builder $cards) => $cards->where('entry_type', 'CARD')->whereHas('lines', fn (Builder $lines) => $lines->where('product_snapshot->company', $company)));
                    if ($company === 'ENTEL') {
                        $q->orWhere('entry_type', 'RECHARGE');
                    }
                });
            }
        }
        if (($data['type'] ?? null) === 'CARD') {
            $query->where('entry_type', 'CARD');
        } elseif (in_array($data['type'] ?? null, ['CREDIT', 'PACKAGES'], true)) {
            $query->where('entry_type', 'RECHARGE')->where('mode', $data['type']);
        }

        return $query;
    }

    public function detail(User $user, CardSaleRegisterEntry $entry): array
    {
        app(CardInventoryAuthorization::class)->authorize($user, 'sell');
        // Reload the authorized projection; never serialize a private digital ledger.
        $entry = $this->entries($user)->where('sale_register.id', $entry->id)->firstOrFail();
        if ($entry->entry_type === 'RECHARGE') {
            return ['lines' => [], 'customer_total_cents' => $entry->customer_total_cents, 'bonus_rate_bps' => $entry->bonus_rate_bps];
        }

        return ['lines' => CardSale::with('lines')->findOrFail($entry->source_id)->lines->map(fn ($line) => ['product_snapshot' => ['label' => $line->product_snapshot['label'] ?? 'Tarjeta'], 'quantity' => $line->quantity, 'unit_price_cents' => $line->unit_price_cents, 'total_cents' => $line->total_cents])->all()];
    }
}
