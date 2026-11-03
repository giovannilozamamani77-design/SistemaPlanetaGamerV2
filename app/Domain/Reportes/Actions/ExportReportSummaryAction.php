<?php

namespace App\Domain\Reportes\Actions;

use App\Domain\Reportes\Models\MonthlyReportAdjustment;
use App\Domain\Reportes\Models\MonthlyReportClose;
use App\Domain\Reportes\Models\ReportExport;
use App\Domain\Reportes\Queries\DailyReportQuery;
use App\Domain\Reportes\Queries\MonthlyReportAdjustmentQuery;
use App\Domain\Reportes\Queries\MonthlyReportCloseQuery;
use App\Infrastructure\Reportes\ReportSummaryCsv;
use App\Infrastructure\Reportes\ReportSummaryExcel;
use App\Infrastructure\Reportes\ReportSummaryPdf;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExportReportSummaryAction
{
    public function execute(User $actor, array $input, string $format): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        abort_unless(in_array($format, ['PDF', 'XLSX', 'CSV'], true), 422);
        $data = Validator::make($input, [
            'mode' => ['required', 'string', Rule::in(['day', 'week', 'month', 'range'])],
            'date' => ['nullable', 'required_unless:mode,range', 'string', 'max:10'],
            'from' => ['nullable', 'string', 'max:10'], 'until' => ['nullable', 'string', 'max:10'],
            'expected_start' => ['nullable', 'required_with:expected_end', 'date_format:Y-m-d'],
            'expected_end' => ['nullable', 'required_with:expected_start', 'date_format:Y-m-d'],
        ])->validate();
        $report = app(DailyReportQuery::class)->execute($actor, $data['date'] ?? '', selection: ['mode' => $data['mode'], 'from' => $data['from'] ?? null, 'until' => $data['until'] ?? null]);
        if (isset($data['expected_start'], $data['expected_end'])) {
            abort_unless($data['expected_start'] === $report['period']['start'] && $data['expected_end'] === $report['period']['end'], 409, 'El periodo cambió. Actualiza Reportes y vuelve a descargar el reporte.');
        }

        return $this->generate($actor, $report, $data['mode'], $format);
    }

    public function frozen(User $actor, int $id, string $format): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        abort_unless(in_array($format, ['PDF', 'XLSX', 'CSV'], true), 404);
        $saved = app(MonthlyReportCloseQuery::class)->detail($actor, $id);
        $report = $saved['report'];
        $report['monthly_close'] = ['id' => $saved['close']->id, 'month' => $saved['close']->month, 'actor' => $saved['close']->actor_name,
            'confirmed_at' => $saved['close']->confirmed_at->setTimezone('America/La_Paz')->format('d/m/Y H:i:s'), 'pending_count' => $saved['close']->pending_count];

        return $this->generate($actor, $report, 'month', $format, $saved['close']);
    }

    public function adjusted(User $actor, int $closeId, int $adjustmentId, string $format): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        abort_unless(in_array($format, ['PDF', 'XLSX', 'CSV'], true), 404);
        $saved = app(MonthlyReportAdjustmentQuery::class)->detail($actor, $closeId, $adjustmentId);
        $row = $saved['adjustment'];
        $close = $saved['close'];
        $report = $row->snapshot['report'];
        $report['monthly_close'] = ['id' => $close->id, 'month' => $close->month, 'actor' => $close->actor_name,
            'confirmed_at' => $close->confirmed_at->setTimezone('America/La_Paz')->format('d/m/Y H:i:s'), 'pending_count' => $close->pending_count];
        $report['monthly_adjustment'] = ['id' => $row->id, 'sequence' => $row->sequence, 'actor' => $row->actor_name,
            'confirmed_at' => $row->confirmed_at->setTimezone('America/La_Paz')->format('d/m/Y H:i:s'), 'reason' => $row->reason, 'pending_count' => $row->pending_count,
            'original_sales_cents' => $close->snapshot['report']['sales_cents'], 'original_collections_cents' => $close->snapshot['report']['collections_cents'],
            'sales_adjustment_cents' => $report['sales_cents'] - $close->snapshot['report']['sales_cents'],
            'collections_adjustment_cents' => $report['collections_cents'] - $close->snapshot['report']['collections_cents']];

        return $this->generate($actor, $report, 'month', $format, $close, $row);
    }

    private function generate(User $actor, array $report, string $mode, string $format, ?MonthlyReportClose $close = null, ?MonthlyReportAdjustment $adjustment = null): array
    {
        $generatedAt = now('UTC')->toImmutable();
        $document = ['id' => (string) Str::uuid(), 'actor' => User::findOrFail($actor->id)->name,
            'generated_at' => $generatedAt->setTimezone('America/La_Paz')->format('d/m/Y H:i:s'),
            'mode_label' => match ($mode) {
                'day' => 'Día', 'week' => 'Semana', 'month' => 'Mes', 'range' => 'Rango de fechas'
            }];
        $bytes = match ($format) {
            'PDF' => app(ReportSummaryPdf::class)->render($report, $document)->output(),
            'XLSX' => app(ReportSummaryExcel::class)->render($report, $document),
            'CSV' => app(ReportSummaryCsv::class)->render($report, $document),
        };
        Gate::forUser($actor)->authorize('viewDailyReports');
        ReportExport::create(['document_id' => $document['id'], 'user_id' => $actor->id, 'actor_name' => $document['actor'], 'format' => $format, 'monthly_close_id' => $close?->id, 'monthly_adjustment_id' => $adjustment?->id,
            'period_mode' => $mode, 'period_start' => $report['period']['start'], 'period_end' => $report['period']['end'],
            'previous_start' => $report['comparison']['available'] ? $report['period']['previous_start'] : null,
            'previous_end' => $report['comparison']['available'] ? $report['period']['previous_end'] : null,
            'generated_at' => $generatedAt, 'document_sha256' => hash('sha256', $bytes), 'created_at' => now('UTC')]);

        return ['bytes' => $bytes, 'filename' => ($adjustment ? 'Cierre_ajustado_'.$close->month.'_v'.$adjustment->sequence.'_' : ($close ? 'Cierre_original_'.$close->month.'_' : 'Reporte_')).$report['period']['start'].'_a_'.$report['period']['end'].'.'.strtolower($format)];
    }
}
