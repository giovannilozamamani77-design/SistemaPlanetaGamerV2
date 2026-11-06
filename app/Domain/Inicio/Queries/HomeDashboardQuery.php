<?php

namespace App\Domain\Inicio\Queries;

use App\Domain\Accesorios\Models\AccessorySale;
use App\Domain\Cajas\Models\CashBoxOperation;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Comisiones\Queries\OwnCommissionsQuery;
use App\Domain\Prestamos\Models\Loan;
use App\Domain\Reportes\Queries\DailyReportRecordsQuery;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Models\CardSale;
use App\Domain\Tarjetas\Models\CardStoreSession;
use App\Domain\Tarjetas\Models\EntelRecharge;
use App\Models\User;
use App\Support\Bolivianos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class HomeDashboardQuery
{
    public function execute(User $actor): array
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('panel.acceder');
        $now = CarbonImmutable::now('America/La_Paz');
        $from = $now->startOfDay()->utc();
        $until = $now->addDay()->startOfDay()->utc();
        $administrative = Gate::forUser($user)->allows('viewDailyReports');
        $canOperateBoxes = $user->can('cajas.operar');
        $canSellCards = Gate::forUser($user)->allows('sell', CardProduct::class);
        $canViewLoans = Gate::forUser($user)->allows('viewAny', Loan::class);
        $canSellAccessories = Gate::forUser($user)->allows('create', AccessorySale::class);
        $activity = [];
        $sessions = [];
        $ownedBoxes = 0;
        $ownsCardStore = false;

        if ($canOperateBoxes) {
            $owned = CashBoxSession::query()->where('user_id', $user->id)->where('status', 'ABIERTA')->whereNotNull('active_slot');
            $ownedBoxes = (clone $owned)->count();
            $sessions = $owned->with('box:id,name')->orderByDesc('opened_at')->orderByDesc('id')->limit(3)
                ->get(['id', 'cash_box_id', 'opened_at'])->map(fn (CashBoxSession $session): array => [
                    'box_id' => $session->cash_box_id,
                    'name' => $session->box?->name ?? 'Caja',
                    'opened_at' => $session->opened_at->timezone('America/La_Paz')->format('d/m · H:i'),
                ])->all();
            if (! $administrative) {
                $activity[] = $this->activity('Cajas', $this->today(CashBoxOperation::query(), $user, $from, $until)->where('status', 'VIGENTE')->count(), 'violet');
            }
        }
        if ($canSellCards) {
            $ownsCardStore = CardStoreSession::query()->where('user_id', $user->id)->where('active_slot', 1)->exists();
            if (! $administrative) {
                $activity[] = $this->activity('Tarjetas y recargas', $this->today(CardSale::query(), $user, $from, $until)->count()
                    + $this->today(EntelRecharge::query(), $user, $from, $until)->count(), 'cyan');
            }
        }
        if (! $administrative && $canViewLoans) {
            $activity[] = $this->activity('Préstamos registrados', $this->today(Loan::query(), $user, $from, $until)->where('status', '!=', 'ANULADO')->count(), 'amber');
        }
        if (! $administrative && $canSellAccessories) {
            $activity[] = $this->activity('Ventas de accesorios y teléfonos', $this->today(AccessorySale::query(), $user, $from, $until)->count(), 'rose');
        }

        $metrics = [];
        if ($administrative) {
            $rows = app(DailyReportRecordsQuery::class)->summary($user, $now->toDateString());
            $indexed = array_column($rows, null, 'source');
            $metrics = [
                $this->metric('Cobros de hoy', $this->money(array_sum(array_column($rows, 'collections_cents'))), 'Importes registrados en los módulos.', 'heroicon-o-banknotes', 'violet'),
                $this->metric('Ventas de hoy', $this->money(array_sum(array_column($rows, 'sales_cents'))), 'Tarjetas, recargas, productos y objetos.', 'heroicon-o-shopping-bag', 'cyan'),
                $this->metric('Comisiones de cajas', $this->money($indexed['BOX_FEES']['collections_cents'] ?? 0), 'Comisiones operativas vigentes de hoy.', 'heroicon-o-arrows-right-left', 'amber'),
                $this->metric('Cajas abiertas', (string) CashBoxSession::query()->where('status', 'ABIERTA')->whereNotNull('active_slot')->count(), 'Sesiones abiertas en este momento.', 'heroicon-o-building-storefront', 'rose'),
            ];
            foreach ([
                ['Cajas', ['BOX_FEES'], 'violet'],
                ['Tarjetas y recargas', ['CARDS', 'CREDIT', 'PACKAGES'], 'cyan'],
                ['Préstamos', ['OBJECT_SALE', 'TERM_PAYMENT', 'SETTLEMENT'], 'amber'],
                ['Accesorios y teléfonos', ['ACCESSORY', 'PHONE'], 'rose'],
            ] as [$label, $sources, $tone]) {
                $amount = array_sum(array_map(fn (string $source): int => $indexed[$source]['collections_cents'] ?? 0, $sources));
                $activity[] = ['label' => $label, 'value' => $amount, 'display' => $this->money($amount), 'tone' => $tone];
            }
        } else {
            if ($canOperateBoxes) {
                $metrics[] = $this->metric('Cajas a tu cargo', (string) $ownedBoxes, 'Tus sesiones abiertas para continuar.', 'heroicon-o-building-storefront', 'violet');
            }
            if ($canSellCards || $canSellAccessories) {
                $sales = array_sum(array_column(array_filter($activity, fn (array $row): bool => in_array($row['tone'], ['cyan', 'rose'], true)), 'value'));
                $metrics[] = $this->metric('Tus ventas de hoy', (string) $sales, 'Tickets y recargas registrados por ti.', 'heroicon-o-shopping-bag', 'cyan');
            }
            if ($canViewLoans) {
                $loans = collect($activity)->firstWhere('tone', 'amber')['value'] ?? 0;
                $metrics[] = $this->metric('Tus préstamos de hoy', (string) $loans, 'Registros vigentes creados por ti.', 'heroicon-o-document-text', 'amber');
            }
            if ($user->can('perfil.ver')) {
                $commissions = app(OwnCommissionsQuery::class)->overview($user);
                $metrics[] = $this->metric('Tus comisiones pendientes', $this->money($commissions['pending_cents']), 'Ganadas y todavía no pagadas.', 'heroicon-o-sparkles', 'rose');
            }
        }
        $total = array_sum(array_column($activity, 'value'));

        return [
            'operations' => app(HomeOperationsQuery::class)->execute($user),
            'name' => $user->name,
            'administrative' => $administrative,
            'date' => $now->locale('es')->translatedFormat('l, d \d\e F'),
            'checked_at' => $now->format('H:i'),
            'metrics' => $metrics,
            'chart' => array_map(fn (array $row): array => [...$row, 'percent' => $total > 0 ? (int) round($row['value'] * 100 / $total) : 0], $activity),
            'chart_total' => $administrative ? $this->money($total) : (string) $total,
            'has_activity' => $total > 0,
            'sessions' => $sessions,
            'owned_boxes' => $ownedBoxes,
            'owns_card_store' => $ownsCardStore,
            'can_operate_boxes' => $canOperateBoxes,
        ];
    }

    private function today(Builder $query, User $user, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return $query->where('user_id', $user->id)->where('created_at', '>=', $from)->where('created_at', '<', $until);
    }

    private function money(int $cents): string
    {
        return 'Bs '.Bolivianos::formatCompact($cents);
    }

    private function metric(string $label, string $value, string $note, string $icon, string $tone): array
    {
        return compact('label', 'value', 'note', 'icon', 'tone');
    }

    private function activity(string $label, int $value, string $tone): array
    {
        return ['label' => $label, 'value' => $value, 'display' => (string) $value, 'tone' => $tone];
    }
}
