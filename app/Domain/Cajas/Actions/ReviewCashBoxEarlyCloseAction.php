<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Authorization\CashBoxCommand;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Domain\Cajas\Models\CashBoxEarlyRequest;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewCashBoxEarlyCloseAction
{
    public function execute(User $actor, int $requestId, array $data): CashBoxEarlyRequest
    {
        $data['reason'] = is_string($data['reason'] ?? null) ? trim($data['reason']) : ($data['reason'] ?? null);
        Validator::make($data, ['decision' => ['required', Rule::in(['APROBADA', 'RECHAZADA'])], 'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'request_key' => ['required', 'uuid'], 'exception' => ['sometimes', 'boolean']])->validate();
        $target = CashBoxEarlyRequest::findOrFail($requestId);
        $id = app(CashBoxCommand::class)->execute($actor, $target->cash_box_session_id, false, 'review_early_close', [...$data, 'early_request_id' => $requestId], function (User $admin, $session) use ($requestId, $data): int {
            app(AdministrativeReview::class)->authorize($admin, 'cajas.liberar', $session->user_id, $data['exception'] ?? false, $data['reason']);
            $request = CashBoxEarlyRequest::whereKey($requestId)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'PENDIENTE') {
                if ($request->status !== $data['decision']) {
                    throw ValidationException::withMessages(['reason' => 'La solicitud ya tiene una decisión. Actualiza el listado.']);
                }

                return $request->id;
            }
            if ($session->status !== 'ABIERTA' || $session->active_slot === null) {
                throw ValidationException::withMessages(['reason' => 'La caja ya no está abierta. No se puede autorizar este cierre.']);
            }
            $request->update(['status' => $data['decision'], 'decided_by' => $admin->id, 'decided_at' => now(), 'decision_reason' => trim($data['reason'])]);
            CashBoxAudit::create(['cash_box_id' => $session->cash_box_id, 'actor_user_id' => $admin->id, 'request_key' => $data['request_key'],
                'action' => 'CIERRE_ANTICIPADO_'.$data['decision'], 'payload_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                'before' => ['status' => 'PENDIENTE'], 'after' => ['request_id' => $request->id, 'status' => $request->status], 'reason' => $data['reason'], 'created_at' => now()]);

            return $request->id;
        });

        return CashBoxEarlyRequest::findOrFail($id);
    }
}
