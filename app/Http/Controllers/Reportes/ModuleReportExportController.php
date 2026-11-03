<?php

namespace App\Http\Controllers\Reportes;

use App\Domain\Reportes\Actions\ExportModuleReportAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ModuleReportExportController
{
    public function __invoke(Request $request, string $module, string $format, ExportModuleReportAction $action): Response
    {
        $result = $action->execute($request->user(), $module, (string) $request->query('month', ''), strtoupper($format));

        return response($result['bytes'])->header('Content-Type', $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->header('Content-Disposition', 'attachment; filename="'.$result['filename'].'"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
