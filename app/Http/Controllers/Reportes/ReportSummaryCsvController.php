<?php

namespace App\Http\Controllers\Reportes;

use App\Domain\Reportes\Actions\ExportReportSummaryCsvAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportSummaryCsvController
{
    public function __invoke(Request $request, ExportReportSummaryCsvAction $action): Response
    {
        $result = $action->execute($request->user(), $request->query());

        return response($result['bytes'])->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$result['filename'].'"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
