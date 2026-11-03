<?php

namespace App\Domain\Reportes\Queries;

use App\Domain\Reportes\Models\MonthlyReportClose;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MonthlyReportCloseQuery
{
    public function forMonth(User $actor, string $month): ?MonthlyReportClose
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        app(MonthlyReportSnapshotQuery::class)->month($month);

        return MonthlyReportClose::where('month', $month)->first();
    }

    public function history(User $actor, int $page = 1): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        Validator::make(['page' => $page], ['page' => ['integer', 'between:1,1000000']])->validate();

        return MonthlyReportClose::select(['id', 'month', 'actor_name', 'confirmed_at', 'source_count', 'pending_count'])
            ->orderByDesc('month')->paginate(5, ['*'], 'monthlyHistoryPage', $page);
    }

    public function detail(User $actor, int $id, string $kind = 'records', int $page = 1): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        Validator::make(compact('kind', 'page'), ['kind' => [Rule::in(['records', 'pending'])], 'page' => ['integer', 'between:1,1000000']])->validate();
        $close = MonthlyReportClose::findOrFail($id);
        abort_unless(hash_equals($close->snapshot_hash, hash('sha256', json_encode($close->snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))), 409, 'No se pudo verificar la integridad del cierre.');
        $rows = $close->snapshot[$kind];

        return ['close' => $close, 'report' => $close->snapshot['report'], 'evidence' => new LengthAwarePaginator(array_slice($rows, ($page - 1) * 5, 5), count($rows), 5, $page, ['pageName' => 'monthlyEvidencePage'])];
    }
}
