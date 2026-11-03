<?php

namespace App\Domain\Reportes\Actions;

use App\Domain\Reportes\Models\ReportExport;
use App\Domain\Reportes\Queries\ModuleReportQuery;
use App\Infrastructure\Reportes\ModuleReportFile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ExportModuleReportAction
{
    public function execute(User $actor, string $module, string $month, string $format): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        abort_unless(in_array($format, ['PDF', 'XLSX'], true), 404);
        $report = app(ModuleReportQuery::class)->execute($actor, $module, $month);
        $bytes = app(ModuleReportFile::class)->render($report, $format);
        Gate::forUser($actor)->authorize('viewDailyReports');
        ReportExport::create(['document_id' => (string) Str::uuid(), 'user_id' => $actor->id, 'actor_name' => $actor->fresh()->name, 'format' => $format, 'export_scope' => 'MODULE', 'detail_source' => $module, 'period_mode' => 'month', 'period_start' => $report['period']['start'], 'period_end' => $report['period']['end'], 'previous_start' => $report['period']['comparison_available'] ? $report['period']['previous_start'] : null, 'previous_end' => $report['period']['comparison_available'] ? $report['period']['previous_end'] : null, 'generated_at' => now(), 'created_at' => now(), 'document_sha256' => hash('sha256', $bytes)]);

        return ['bytes' => $bytes, 'filename' => 'Reporte_'.$module.'_'.$month.'.'.strtolower($format)];
    }
}
