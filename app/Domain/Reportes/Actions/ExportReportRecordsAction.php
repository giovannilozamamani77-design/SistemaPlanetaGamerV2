<?php

namespace App\Domain\Reportes\Actions;

use App\Domain\Reportes\Data\ReportPeriod;
use App\Domain\Reportes\Models\ReportExport;
use App\Domain\Reportes\Queries\DailyReportRecordsQuery;
use App\Infrastructure\Reportes\ReportRecordsFile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExportReportRecordsAction
{
    public function execute(User $actor, array $input, string $format): array
    {
        Gate::forUser($actor)->authorize('viewDailyReports');
        abort_unless(in_array($format, ['XLSX', 'CSV'], true), 404);
        $data = Validator::make($input, [
            'mode' => ['required', Rule::in(['day', 'week', 'month', 'range'])],
            'date' => ['nullable', 'required_unless:mode,range', 'string', 'max:10'],
            'from' => ['nullable', 'string', 'max:10'], 'until' => ['nullable', 'string', 'max:10'],
            'expected_start' => ['required', 'date_format:Y-m-d'], 'expected_end' => ['required', 'date_format:Y-m-d'],
            'metric' => ['required', Rule::in(['sales', 'collections'])], 'source' => ['required', 'string', 'max:32'],
        ])->validate();
        $period = app(ReportPeriod::class)->resolve($data['mode'], $data['date'] ?? '', $data['from'] ?? null, $data['until'] ?? null);
        abort_unless($data['expected_start'] === $period['start'] && $data['expected_end'] === $period['end'], 409, 'El periodo cambió. Actualiza Reportes y vuelve a abrir el detalle.');
        $at = now('UTC')->toImmutable();
        $document = ['id' => (string) Str::uuid(), 'actor' => User::findOrFail($actor->id)->name, 'generated_at' => $at->setTimezone('America/La_Paz')->format('d/m/Y H:i:s')];
        $result = app(DailyReportRecordsQuery::class)->export($actor, $period['start'], $data['metric'], $data['source'], $period['end'],
            function (array $context, iterable $records) use ($format, $document): array {
                return ['bytes' => app(ReportRecordsFile::class)->render($context, $document, $records, $format), 'context' => $context];
            });
        Gate::forUser($actor)->authorize('viewDailyReports');
        ReportExport::create(['document_id' => $document['id'], 'user_id' => $actor->id, 'actor_name' => $document['actor'], 'format' => $format,
            'export_scope' => 'DETAIL', 'detail_metric' => $data['metric'], 'detail_source' => $data['source'],
            'period_mode' => $data['mode'], 'period_start' => $period['start'], 'period_end' => $period['end'],
            'generated_at' => $at, 'document_sha256' => hash('sha256', $result['bytes']), 'created_at' => $at]);

        return ['bytes' => $result['bytes'], 'filename' => 'Detalle_'.$data['metric'].'_'.$data['source'].'_'.$period['start'].'_a_'.$period['end'].'.'.strtolower($format)];
    }
}
