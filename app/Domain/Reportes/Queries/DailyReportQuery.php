<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Reportes\Calculators\DailyComparisonCalculator;
use App\Domain\Reportes\Data\ReportPeriod;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DailyReportQuery
{
    public function execute(User $actor, string $date, ?array $detail = null, ?array $selection = null): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');

        $period = app(ReportPeriod::class)->resolve($selection['mode'] ?? 'day', $date, $selection['from'] ?? null, $selection['until'] ?? null);

        return DB::transaction(function () use ($actor, $detail, $period): array {
            $query = app(DailyReportRecordsQuery::class);
            $rows = $query->summary($actor, $period['start'], $period['end']);

            return [
                'date' => $period['start'],
                'period' => $period,
                'is_today' => $period['mode'] === 'day' && $period['is_current'],
                'comparison' => $this->comparison($actor, $period, $rows),
                'checked_at' => now('America/La_Paz')->format('d/m/Y H:i:s'),
                'sales_cents' => array_sum(array_column($rows, 'sales_cents')),
                'collections_cents' => array_sum(array_column($rows, 'collections_cents')),
                'rows' => $rows,
                'balances' => $this->balances(),
                'detail' => $detail === null ? null : $query->records($actor, $period['start'], $detail['metric'], $detail['source'], $detail['page'], $detail['per_page'], $period['end']),
            ];
        });
    }

    private function comparison(User $actor, array $period, array $rows): array
    {
        $previousDate = $period['previous_start'];
        if (! $period['comparison_available']) {
            return ['previous_date' => $previousDate, 'available' => false, 'sales' => null, 'collections' => null];
        }
        $previousRows = app(DailyReportRecordsQuery::class)->summary($actor, $previousDate, $period['previous_end']);
        $calculator = app(DailyComparisonCalculator::class);

        return ['previous_date' => $previousDate, 'available' => true,
            'sales' => $calculator->compare(array_sum(array_column($rows, 'sales_cents')), array_sum(array_column($previousRows, 'sales_cents'))),
            'collections' => $calculator->compare(array_sum(array_column($rows, 'collections_cents')), array_sum(array_column($previousRows, 'collections_cents')))];
    }

    private function balances(): array
    {
        $store = DB::table('card_stores')->where('code', 'TIENDA')->first(['cash_balance_cents', 'initialized_at']);
        $fund = DB::table('loan_funds')->where('code', 'YAPE')->first(['balance_cents', 'initialized_at']);
        $commercial = DB::table('accessory_commercial_funds')->where('code', 'GENERAL')->value('balance_cents');
        $rows = [
            ['label' => 'Tarjetas · Efectivo', 'amount_cents' => $store?->initialized_at ? (int) $store->cash_balance_cents : null, 'note' => $store?->initialized_at ? 'Disponible registrado; reservas de sobrantes excluidas.' : 'Sin conteo inicial confirmado.'],
            ['label' => 'Préstamos · Yape', 'amount_cents' => $fund?->initialized_at ? (int) $fund->balance_cents : null, 'note' => $fund?->initialized_at ? 'Disponible para prestar según el fondo registrado.' : 'Fondo sin inicializar.'],
            ['label' => 'Accesorios · Efectivo', 'amount_cents' => $commercial === null ? null : (int) $commercial, 'note' => $commercial === null ? 'Fondo todavía sin registrar.' : 'Ventas, ingresos y depósitos ya aplicados al fondo.'],
        ];
        $latest = DB::table('cash_box_sessions')->selectRaw('MAX(id)')->groupBy('cash_box_id');
        $sessions = CashBoxSession::query()->whereIn('id', $latest)->with(['box:id,name,is_active', 'configuration:id,accounts'])->orderBy('cash_box_id')->get();
        $held = DB::table('international_transfers')->where('local_held_cents', '>', 0)->groupBy('cash_box_id', 'component')
            ->selectRaw('cash_box_id, component, SUM(local_held_cents) AS amount')->get()->groupBy('cash_box_id');
        foreach ($sessions as $session) {
            $reserved = $held->get($session->cash_box_id, collect())->keyBy('component');
            $balances = $session->current_balances ?? $session->handover_balances ?? $session->opening_balances;
            $usable = $session->box?->is_active && (($session->status === 'ABIERTA' && $session->active_slot !== null)
                || (in_array($session->status, ['CERRADA', 'CERRADA_CON_INCIDENCIAS'], true) && $session->released_at && $session->handover_balances !== null));
            foreach ($balances as $code => $amount) {
                $available = $amount - (int) ($reserved->get($code)?->amount ?? 0);
                $rows[] = ['label' => 'Cajas · '.$session->box?->name.' · '.($session->configuration?->accounts[$code]['name'] ?? $code),
                    'amount_cents' => $usable && $available >= 0 ? $available : null,
                    'note' => ! $usable ? 'Saldo registrado Bs '.Bolivianos::formatCompact($amount).'; caja inactiva o pendiente de liberación, no disponible.'
                        : ($available < 0 ? 'Saldo reservado inconsistente: requiere revisión.' : 'Última sesión; custodia internacional excluida. Saldo registrado, sin verificación bancaria externa.')];
            }
        }
        $uncounted = DB::table('cash_boxes')->whereNotIn('id', DB::table('cash_box_sessions')->select('cash_box_id'))->count();
        if ($uncounted) {
            $rows[] = ['label' => 'Cajas sin apertura confirmada: '.$uncounted, 'amount_cents' => null, 'note' => 'El capital configurado no acredita dinero contado.'];
        }

        return $rows;
    }
}
