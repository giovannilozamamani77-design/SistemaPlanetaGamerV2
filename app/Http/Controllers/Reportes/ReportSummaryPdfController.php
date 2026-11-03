<?php

namespace App\Http\Controllers\Reportes;

use App\Domain\Reportes\Actions\ExportReportSummaryPdfAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportSummaryPdfController
{
    public function __invoke(Request $request, ExportReportSummaryPdfAction $action): Response
    {
        $result = $action->execute($request->user(), $request->query());

        return response($result['bytes'])->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$result['filename'].'"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
