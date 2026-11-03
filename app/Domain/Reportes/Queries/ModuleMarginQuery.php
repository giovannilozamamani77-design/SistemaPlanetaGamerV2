<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Prestamos\Models\LoanObjectSale;
use App\Domain\Prestamos\Models\LoanSettlement;
use App\Domain\Prestamos\Models\LoanTermChange;
use App\Domain\Reportes\Data\ModuleReportDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ModuleMarginQuery
{
    public function execute(User $actor, string $module, string $start, string $end, array $rows): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        app(ModuleReportDefinition::class)->get($module);
        $context = app(DailyReportRecordsQuery::class)->context($actor, $start, untilDate: $end);
        $result = ['known_cents' => 0, 'capital_recovered_cents' => 0, 'pending' => 0, 'commissions_cents' => null, 'commission_pending' => 0, 'after_commissions_cents' => null, 'daily' => [], 'parts' => []];
        if ($module === 'BOXES') {
            $result['known_cents'] = array_sum(array_column($rows, 'collections_cents'));
            $result['parts']['BOX_FEES'] = ['known_cents' => $result['known_cents'], 'pending' => 0];

            return $result;
        }
        if ($module === 'LOANS') {
            foreach ([LoanTermChange::class => 'TERM_PAYMENT', LoanSettlement::class => 'SETTLEMENT', LoanObjectSale::class => 'OBJECT_SALE'] as $model => $source) {
                $part = ['source' => $source, 'known_cents' => 0, 'pending' => 0];
                $events = 0;
                foreach ($model::where('created_at', '>=', $context['from'])->where('created_at', '<', $context['until'])->lazyById(500) as $event) {
                    $events++;
                    $snapshot = $event->summary;
                    $keys = match ($source) {
                        'TERM_PAYMENT' => ['interest_paid_cents', 'late_fee_cents'],
                        'SETTLEMENT' => ['interest_cents', 'late_fee_cents'],
                        default => ['total_profit_cents'],
                    };
                    $valid = true;
                    foreach ($keys as $key) {
                        $valid = $valid && array_key_exists($key, $snapshot) && is_int($snapshot[$key]);
                    }
                    if (! $valid) {
                        $part['pending']++;

                        continue;
                    }
                    $amount = array_sum(array_intersect_key($snapshot, array_flip($keys)));
                    $part['known_cents'] += $amount;
                    if ($source !== 'TERM_PAYMENT' && isset($snapshot['capital_cents']) && is_int($snapshot['capital_cents'])) {
                        $result['capital_recovered_cents'] += $snapshot['capital_cents'];
                    }
                    $day = $event->created_at->setTimezone('America/La_Paz')->toDateString();
                    $result['daily'][$day] = ($result['daily'][$day] ?? 0) + $amount;
                }
                $part['pending'] += max(0, ($rows[$source]['operations'] ?? 0) - $events);
                $result['parts'][$source] = $part;
                $result['known_cents'] += $part['known_cents'];
                $result['pending'] += $part['pending'];
            }

            return $result;
        }
        $from = $context['from'];
        $until = $context['until'];
        $zoneDay = DB::connection()->getDriverName() === 'sqlite' ? "date(sale.created_at, '-4 hours')" : 'DATE(DATE_SUB(sale.created_at, INTERVAL 4 HOUR))';
        foreach (($module === 'CARDS' ? ['CARDS', 'CREDIT', 'PACKAGES'] : ['ACCESSORY', 'PHONE']) as $source) {
            if ($source === 'CARDS') {
                $query = DB::table('card_sale_lines as line')->join('card_sales as sale', 'sale.id', '=', 'line.card_sale_id');
                $gross = 'line.gross_margin_cents';
            } elseif (in_array($source, ['CREDIT', 'PACKAGES'], true)) {
                $query = DB::table('entel_recharges as sale')->where('sale.kind', $source);
                $gross = 'sale.gross_margin_cents';
            } else {
                $query = DB::table('accessory_sale_lines as line')->join('accessory_sales as sale', 'sale.id', '=', 'line.sale_id')->where('line.kind', $source);
                $gross = 'line.total_cents - line.cost_cents';
            }
            $cost = in_array($source, ['CREDIT', 'PACKAGES'], true) ? 'sale.cost_cents' : 'line.cost_cents';
            $query->where('sale.created_at', '>=', $from)->where('sale.created_at', '<', $until);
            $days = (clone $query)->selectRaw($zoneDay.' as day, COALESCE(SUM('.$gross.'),0) as known, SUM(CASE WHEN '.$cost.' IS NULL THEN 1 ELSE 0 END) as pending')->groupByRaw($zoneDay)->get();
            $part = ['source' => $source, 'known_cents' => 0, 'pending' => 0];
            foreach ($days as $day) {
                $part['known_cents'] += (int) $day->known;
                $part['pending'] += (int) $day->pending;
                $result['daily'][$day->day] = ($result['daily'][$day->day] ?? 0) + (int) $day->known;
            }
            $result['parts'][$source] = $part;
            $result['known_cents'] += $part['known_cents'];
            $result['pending'] += $part['pending'];
            if ($module === 'ACCESSORIES') {
                $commission = DB::table('commission_entries')->select('sale_line_id')->selectRaw('SUM(amount_cents) as amount')->groupBy('sale_line_id');
                $total = (clone $query)->leftJoinSub($commission, 'commission', 'commission.sale_line_id', '=', 'line.id')->selectRaw('COALESCE(SUM(commission.amount),0) as amount, COALESCE(SUM(CASE WHEN commission.sale_line_id IS NULL THEN 1 ELSE 0 END),0) as pending')->first();
                $result['commissions_cents'] = ($result['commissions_cents'] ?? 0) + (int) $total->amount;
                $result['commission_pending'] += (int) $total->pending;
            }
        }
        if ($module === 'ACCESSORIES' && $result['pending'] === 0 && $result['commission_pending'] === 0) {
            $result['after_commissions_cents'] = $result['known_cents'] - $result['commissions_cents'];
        }

        return $result;
    }
}
