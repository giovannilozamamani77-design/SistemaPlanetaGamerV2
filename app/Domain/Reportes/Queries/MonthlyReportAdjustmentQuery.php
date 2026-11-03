<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Reportes\Calculators\MonthlyReportAdjustmentCalculator;
use App\Domain\Reportes\Models\MonthlyReportAdjustment;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class MonthlyReportAdjustmentQuery
{
    public function state(User $actor, int $closeId): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        $saved = app(MonthlyReportCloseQuery::class)->detail($actor, $closeId);
        $latest = MonthlyReportAdjustment::where('monthly_close_id', $closeId)->orderByDesc('sequence')->first();
        if ($latest) {
            $this->verify($latest, $saved['close']->snapshot_hash);
        }
        $snapshot = $latest?->snapshot ?? $saved['close']->snapshot;

        return ['close' => $saved['close'], 'latest' => $latest, 'snapshot' => $snapshot,
            'original_sales_cents' => $saved['report']['sales_cents'], 'original_collections_cents' => $saved['report']['collections_cents'],
            'sales_adjustment_cents' => $snapshot['report']['sales_cents'] - $saved['report']['sales_cents'],
            'collections_adjustment_cents' => $snapshot['report']['collections_cents'] - $saved['report']['collections_cents']];
    }

    public function preview(User $actor, int $closeId): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');

        return DB::transaction(function () use ($actor, $closeId): array {
            $state = $this->state($actor, $closeId);
            $current = app(MonthlyReportSnapshotQuery::class)->execute($actor, $state['close']->month);
            $result = app(MonthlyReportAdjustmentCalculator::class)->calculate($state['close']->snapshot, $state['snapshot'], $current['snapshot']);
            $baseSequence = $state['latest']?->sequence ?? 0;
            $baseHash = $state['latest']?->snapshot_hash ?? $state['close']->snapshot_hash;
            $hash = app(MonthlyReportAdjustmentCalculator::class)->hash([$closeId, $baseSequence, $baseHash, $result]);

            return $result + ['close' => $state['close'], 'base_sequence' => $baseSequence, 'base_hash' => $baseHash, 'previous_id' => $state['latest']?->id, 'preview_hash' => $hash];
        });
    }

    public function history(User $actor, int $closeId, int $page = 1): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        app(MonthlyReportCloseQuery::class)->detail($actor, $closeId);
        Validator::make(['page' => $page], ['page' => ['integer', 'between:1,1000000']])->validate();

        return MonthlyReportAdjustment::select(['id', 'monthly_close_id', 'sequence', 'actor_name', 'reason', 'sales_delta_cents', 'collections_delta_cents', 'confirmed_at'])
            ->where('monthly_close_id', $closeId)->orderByDesc('sequence')->paginate(5, ['*'], 'monthlyAdjustmentHistory', $page);
    }

    public function detail(User $actor, int $closeId, int $id, int $page = 1): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        $close = app(MonthlyReportCloseQuery::class)->detail($actor, $closeId)['close'];
        Validator::make(['page' => $page], ['page' => ['integer', 'between:1,1000000']])->validate();
        $adjustment = MonthlyReportAdjustment::where('monthly_close_id', $closeId)->findOrFail($id);
        $this->verify($adjustment, $close->snapshot_hash);

        return ['adjustment' => $adjustment, 'close' => $close, 'changes' => new LengthAwarePaginator(array_slice($adjustment->changes, ($page - 1) * 5, 5), count($adjustment->changes), 5, $page, ['pageName' => 'monthlyAdjustmentDetails'])];
    }

    private function verify(MonthlyReportAdjustment $adjustment, string $originalHash): void
    {
        $hash = app(MonthlyReportAdjustmentCalculator::class)->hash($adjustment->snapshot);
        $previous = $adjustment->previous_adjustment_id === null ? null : MonthlyReportAdjustment::where('monthly_close_id', $adjustment->monthly_close_id)->find($adjustment->previous_adjustment_id);
        abort_unless(hash_equals($adjustment->snapshot_hash, $hash) && hash_equals($adjustment->before_hash, $previous?->snapshot_hash ?? $originalHash)
            && ($adjustment->previous_adjustment_id === null || $previous !== null) && $adjustment->sequence === ($previous?->sequence ?? 0) + 1, 409, 'No se pudo verificar la integridad del ajuste.');
    }
}
