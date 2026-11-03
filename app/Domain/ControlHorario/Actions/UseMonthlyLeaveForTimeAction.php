<?php

namespace App\Domain\ControlHorario\Actions;

use App\Domain\ControlHorario\Models\LeaveRequest;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UseMonthlyLeaveForTimeAction
{
    public function execute(User $actor, TimeIncident $case, string $reason, string $key, bool $exception = false): LeaveRequest
    {
        app(AdministrativeReview::class)->authorize($actor, 'controlhorario.revisar', $case->user_id, $exception, $reason);

        return DB::transaction(function () use ($actor, $case, $reason, $key, $exception): LeaveRequest {
            $request = app(RequestLeaveAction::class)->execute($actor, ['kind' => $case->kind === 'RETRASO' ? 'COMPENSAR_RETRASOS' : 'COMPENSAR_FALTA', 'incident_id' => $case->id, 'reason' => $reason, 'request_key' => $key], $case->user);

            return app(ApproveLeaveAction::class)->execute($actor, $request, true, $reason, $exception);
        });
    }
}
