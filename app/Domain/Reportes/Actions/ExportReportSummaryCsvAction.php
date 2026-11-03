<?php

namespace App\Domain\Reportes\Actions;

use App\Models\User;

class ExportReportSummaryCsvAction
{
    public function execute(User $actor, array $input): array
    {
        return app(ExportReportSummaryAction::class)->execute($actor, $input, 'CSV');
    }
}
