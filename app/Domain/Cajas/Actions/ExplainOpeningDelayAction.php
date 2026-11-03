<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Queries\CashBoxClosingStartQuery;
use App\Domain\ControlHorario\Actions\AcknowledgeOpeningDelayAction;
use App\Domain\ControlHorario\Actions\SubmitTimeReasonAction;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExplainOpeningDelayAction
{
    public function execute(User $actor, int $sessionId, string $code, string $reason): TimeIncident
    {
        app(CashBoxClosingStartQuery::class)->execute($actor, $sessionId);

        return DB::transaction(function () use ($actor, $sessionId, $code, $reason): TimeIncident {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $session = CashBoxSession::whereKey($sessionId)->where('user_id', $actor->id)->firstOrFail();
            $case = TimeIncident::where('user_id', $actor->id)->where('source_key', 'opening:'.$session->scheduled_workday_id)->where('kind', 'RETRASO')->first();
            if (! $case || ! ($case->calculation['automatic_debt'] ?? false) || $case->real_minutes <= 0) {
                throw ValidationException::withMessages(['reason' => 'No hay un retraso de apertura registrado para explicar en esta caja.']);
            }
            $case = app(SubmitTimeReasonAction::class)->execute($actor, $case, $code, $reason);
            app(AcknowledgeOpeningDelayAction::class)->execute($actor, $case->id);

            return $case;
        });
    }
}
