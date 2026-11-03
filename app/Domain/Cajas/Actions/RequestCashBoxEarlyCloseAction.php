<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Authorization\CashBoxCommand;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Domain\Cajas\Models\CashBoxEarlyRequest;
use App\Domain\Cajas\Queries\CashBoxClosingAccessQuery;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RequestCashBoxEarlyCloseAction
{
    public function execute(User $actor, int $sessionId, array $data): CashBoxEarlyRequest
    {
        $data['reason'] = is_string($data['reason'] ?? null) ? trim($data['reason']) : ($data['reason'] ?? null);
        Validator::make($data, ['reason' => ['required', 'string', 'min:3', 'max:2000'], 'request_key' => ['required', 'uuid']])->validate();
        $id = app(CashBoxCommand::class)->execute($actor, $sessionId, true, 'request_early_close', $data, function (User $user, $session) use ($data): int {
            $gate = app(CashBoxClosingAccessQuery::class)->execute($user, $session->id);
            if (! $gate['early']) {
                throw ValidationException::withMessages(['reason' => 'El cierre ya está permitido o el control está desactivado. No necesitas solicitar permiso.']);
            }
            if ($gate['request']) {
                return $gate['request']->id;
            }
            $request = CashBoxEarlyRequest::create(['cash_box_session_id' => $session->id, 'period' => $gate['period'],
                'reason' => trim($data['reason']), 'status' => 'PENDIENTE', 'created_at' => now('UTC')]);
            CashBoxAudit::create(['cash_box_id' => $session->cash_box_id, 'actor_user_id' => $user->id, 'request_key' => $data['request_key'],
                'action' => 'CIERRE_ANTICIPADO_SOLICITADO', 'payload_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                'after' => ['request_id' => $request->id, 'workday_id' => $gate['workday_id'], 'period' => $gate['period']], 'reason' => $data['reason'], 'created_at' => now()]);

            return $request->id;
        });

        return CashBoxEarlyRequest::findOrFail($id);
    }
}
