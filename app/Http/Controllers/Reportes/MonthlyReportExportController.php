<?php

namespace App\Http\Controllers\Reportes;

use App\Domain\Reportes\Actions\ExportMonthlyReportEvidenceAction;
use App\Domain\Reportes\Actions\ExportReportSummaryAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MonthlyReportExportController
{
    public function __invoke(Request $request, int $close, string $format, ExportReportSummaryAction $action): Response
    {
        $result = $action->frozen($request->user(), $close, strtoupper($format));

        return $this->download($result, $format);
    }

    public function adjusted(Request $request, int $close, int $adjustment, string $format, ExportReportSummaryAction $action): Response
    {
        return $this->download($action->adjusted($request->user(), $close, $adjustment, strtoupper($format)), $format);
    }

    public function evidence(Request $request, int $close, string $format, ExportMonthlyReportEvidenceAction $action): Response
    {
        return $this->download($action->execute($request->user(), $close, null, strtoupper($format)), $format);
    }

    public function adjustedEvidence(Request $request, int $close, int $adjustment, string $format, ExportMonthlyReportEvidenceAction $action): Response
    {
        return $this->download($action->execute($request->user(), $close, $adjustment, strtoupper($format)), $format);
    }

    private function download(array $result, string $format): Response
    {
        $mime = match ($format) {
            'pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=UTF-8',
        };

        return response($result['bytes'])->header('Content-Type', $mime)
            ->header('Content-Disposition', 'attachment; filename="'.$result['filename'].'"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
