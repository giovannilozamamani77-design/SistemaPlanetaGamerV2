<?php

namespace App\Domain\Reportes\Queries;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MonthlyReportEvidenceQuery
{
    public function execute(User $actor, int $closeId, ?int $adjustmentId = null): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        $saved = app(MonthlyReportCloseQuery::class)->detail($actor, $closeId);
        $close = $saved['close'];
        $adjustment = $adjustmentId === null ? null : app(MonthlyReportAdjustmentQuery::class)->detail($actor, $closeId, $adjustmentId)['adjustment'];
        $snapshot = $adjustment?->snapshot ?? $close->snapshot;
        $changes = $adjustment?->changes ?? [];
        if (count($snapshot['records']) + count($snapshot['pending']) + count($changes) > 50000) {
            throw ValidationException::withMessages(['evidence' => 'La evidencia supera 50000 filas. Esta descarga requiere una exportación en segundo plano; no se entregará un archivo incompleto.']);
        }

        return ['close' => $close, 'adjustment' => $adjustment, 'snapshot' => $snapshot, 'changes' => $changes,
            'version' => $adjustment === null ? 'Original' : 'Ajuste v'.$adjustment->sequence,
            'actor' => $adjustment?->actor_name ?? $close->actor_name,
            'confirmed_at' => ($adjustment?->confirmed_at ?? $close->confirmed_at)->setTimezone('America/La_Paz')->format('d/m/Y H:i:s'),
            'snapshot_hash' => $adjustment?->snapshot_hash ?? $close->snapshot_hash,
            'sales_adjustment_cents' => $snapshot['report']['sales_cents'] - $close->snapshot['report']['sales_cents'],
            'collections_adjustment_cents' => $snapshot['report']['collections_cents'] - $close->snapshot['report']['collections_cents']];
    }
}
