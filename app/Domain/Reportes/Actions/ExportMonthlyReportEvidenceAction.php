<?php

namespace App\Domain\Reportes\Actions;

use App\Domain\Reportes\Models\ReportExport;
use App\Domain\Reportes\Queries\MonthlyReportEvidenceQuery;
use App\Infrastructure\Reportes\MonthlyReportEvidenceFile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ExportMonthlyReportEvidenceAction
{
    public function execute(User $actor, int $closeId, ?int $adjustmentId, string $format): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        abort_unless(in_array($format, ['XLSX', 'CSV'], true), 404);
        $saved = app(MonthlyReportEvidenceQuery::class)->execute($actor, $closeId, $adjustmentId);
        $at = now('UTC')->toImmutable();
        $document = ['id' => (string) Str::uuid(), 'actor' => User::findOrFail($actor->id)->name, 'generated_at' => $at->setTimezone('America/La_Paz')->format('d/m/Y H:i:s')];
        $context = ['period_label' => $saved['snapshot']['report']['period']['label'], 'saved' => $saved];
        $bytes = app(MonthlyReportEvidenceFile::class)->render($context, $document, $saved['snapshot']['records'], $format);
        Gate::forUser($actor)->authorize('viewDailyReports');
        $period = $saved['snapshot']['report']['period'];
        ReportExport::create(['document_id' => $document['id'], 'user_id' => $actor->id, 'actor_name' => $document['actor'], 'format' => $format,
            'export_scope' => 'DETAIL', 'detail_metric' => 'both', 'detail_source' => 'ALL',
            'monthly_close_id' => $saved['close']->id, 'monthly_adjustment_id' => $saved['adjustment']?->id,
            'period_mode' => 'month', 'period_start' => $period['start'], 'period_end' => $period['end'],
            'generated_at' => $at, 'document_sha256' => hash('sha256', $bytes), 'created_at' => $at]);

        return ['bytes' => $bytes, 'filename' => 'Detalle_cierre_'.$saved['close']->month.'_'.($saved['adjustment'] === null ? 'original' : 'v'.$saved['adjustment']->sequence).'.'.strtolower($format)];
    }
}
