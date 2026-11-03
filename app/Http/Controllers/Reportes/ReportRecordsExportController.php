<?php

namespace App\Http\Controllers\Reportes;

use App\Domain\Reportes\Actions\ExportReportRecordsAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportRecordsExportController
{
    public function __invoke(Request $request, string $format, ExportReportRecordsAction $action): Response
    {
        $result = $action->execute($request->user(), $request->query(), strtoupper($format));

        return response($result['bytes'])->header('Content-Type', $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="'.$result['filename'].'"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
