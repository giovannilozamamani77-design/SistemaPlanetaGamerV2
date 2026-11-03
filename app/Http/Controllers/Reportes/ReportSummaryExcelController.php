<?php

namespace App\Http\Controllers\Reportes;

use App\Domain\Reportes\Actions\ExportReportSummaryExcelAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportSummaryExcelController
{
    public function __invoke(Request $request, ExportReportSummaryExcelAction $action): Response
    {
        $result = $action->execute($request->user(), $request->query());

        return response($result['bytes'])->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="'.$result['filename'].'"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
