<?php

namespace App\Domain\Inicio\Queries;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Queries\LoanQuery;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardStore;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Gate;

class HomeOperationsQuery
{
    public function execute(User $actor): array
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('panel.acceder');
        $administrative = Gate::forUser($user)->allows('viewDailyReports');

        return [
            'boxes' => $user->can('cajas.operar') ? $this->boxes($user, $administrative) : null,
            'loans' => Gate::forUser($user)->allows('viewAny', Loan::class) ? $this->loans($user, $administrative) : null,
            'cards' => Gate::forUser($user)->allows('sell', CardProduct::class) || Gate::forUser($user)->allows('viewAny', CardProduct::class) ? $this->cards() : null,
        ];
    }

    private function boxes(User $user, bool $administrative): array
    {
        $latest = CashBoxSession::query()->selectRaw('cash_box_id, MAX(id) AS session_id')->groupBy('cash_box_id');
        $query = CashBox::query()->where('cash_boxes.is_active', true)
            ->leftJoinSub($latest, 'latest', fn (JoinClause $join) => $join->on('latest.cash_box_id', '=', 'cash_boxes.id'))
            ->leftJoin('cash_box_sessions as current', 'current.id', '=', 'latest.session_id');
        if (! $administrative) {
            $query->where('current.user_id', $user->id)->where('current.status', 'ABIERTA')->whereNotNull('current.active_slot');
        }
        $state = "CASE WHEN current.id IS NULL THEN 'UNOPENED' WHEN current.status = 'ABIERTA' AND current.active_slot IS NOT NULL THEN 'OPEN' WHEN current.status IN ('CERRADA', 'CERRADA_CON_INCIDENCIAS') AND current.released_at IS NULL THEN 'PENDING' ELSE 'CLOSED' END";
        $counts = (clone $query)->selectRaw($state.' AS home_state, COUNT(*) AS total')->groupByRaw($state)->get()->pluck('total', 'home_state');
        $labels = ['OPEN' => 'Abiertas', 'PENDING' => 'Pendientes', 'CLOSED' => 'Cerradas', 'UNOPENED' => 'Sin apertura'];
        $tones = ['OPEN' => 'green', 'PENDING' => 'amber', 'CLOSED' => 'slate', 'UNOPENED' => 'violet'];
        $rows = $query->orderBy('cash_boxes.name')->orderBy('cash_boxes.id')->limit(4)
            ->select(['cash_boxes.id', 'cash_boxes.name', 'cash_boxes.type'])->selectRaw($state.' AS home_state')->get()
            ->map(fn ($box): array => ['name' => $box->name, 'type' => $box->type === 'TIGO_MONEY' ? 'Tigo Money' : 'Agente BCP',
                'state' => $box->home_state, 'label' => match ($box->home_state) {
                    'OPEN' => 'Abierta', 'PENDING' => 'Pendiente', 'CLOSED' => 'Cerrada', default => 'Sin apertura'
                }, 'tone' => $tones[$box->home_state]])->all();
        $chart = [];
        foreach ($labels as $code => $label) {
            $chart[] = ['label' => $label, 'value' => (int) ($counts[$code] ?? 0), 'tone' => $tones[$code]];
        }

        return ['items' => $rows, ...$this->chart($chart)];
    }

    private function loans(User $user, bool $administrative): array
    {
        $reader = app(LoanQuery::class);
        $query = $reader->loans($user);
        if (! $administrative) {
            $query->where('user_id', $user->id);
        }
        $rows = [];
        foreach ([['ACTIVO', 'Activos', 'green'], ['CON_MULTA', 'Con multa', 'amber'], ['PERDIDA_TOTAL', 'Pérdida total', 'rose']] as [$state, $label, $tone]) {
            $rows[] = ['label' => $label, 'value' => $reader->filterStatus(clone $query, $state)->count(), 'tone' => $tone];
        }

        return $this->chart($rows);
    }

    private function cards(): array
    {
        $store = CardStore::query()->where('code', 'TIENDA')->first(['id', 'initialized_at']);
        $rows = [];
        $zeroProducts = [];
        if ($store?->initialized_at) {
            $query = CardProduct::query()->where('card_products.is_active', true)->leftJoin('card_stocks as stock', function (JoinClause $join) use ($store): void {
                $join->on('stock.card_product_id', '=', 'card_products.id')->where('stock.card_store_id', '=', $store->id);
            });
            $totals = (clone $query)->selectRaw('company, SUM(COALESCE(stock.quantity, 0)) AS total')->groupBy('company')->get()->pluck('total', 'company');
            foreach (['ENTEL', 'TIGO', 'VIVA'] as $company) {
                $rows[] = ['label' => ucfirst(strtolower($company)), 'value' => (int) ($totals[$company] ?? 0), 'tone' => 'cyan'];
            }
            $zeroProducts = $query->whereRaw('COALESCE(stock.quantity, 0) = 0')->orderBy('company')->orderBy('face_value_cents')->orderBy('card_products.id')->limit(4)
                ->get(['card_products.id', 'company', 'face_value_cents'])->map(fn (CardProduct $product): string => ucfirst(strtolower($product->company)).' · '.$product->cutLabel())->all();
        }

        return ['initialized' => (bool) $store?->initialized_at, 'rows' => $rows, 'total' => array_sum(array_column($rows, 'value')), 'zero_products' => $zeroProducts];
    }

    private function chart(array $rows): array
    {
        $colors = ['green' => '#36b889', 'amber' => '#e2af64', 'rose' => '#db7895', 'slate' => '#738299', 'violet' => '#9b86ee'];
        $total = array_sum(array_column($rows, 'value'));
        $offset = 0;
        $segments = [];
        foreach ($rows as $row) {
            if ($row['value'] === 0) {
                continue;
            }
            $end = $offset + $row['value'] * 100 / $total;
            $segments[] = $colors[$row['tone']].' '.$offset.'% '.$end.'%';
            $offset = $end;
        }

        return ['rows' => $rows, 'total' => $total, 'gradient' => $segments ? 'conic-gradient('.implode(', ', $segments).')' : '#73829922'];
    }
}
