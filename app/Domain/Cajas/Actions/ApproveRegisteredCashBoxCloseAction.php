<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Authorization\CashBoxCommand;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ApproveRegisteredCashBoxCloseAction
{
    public function execute(User $actor, int $closeId, array $data): CashBoxClose
    {
        Validator::make($data, ['reason' => ['nullable', 'string', 'max:2000'], 'exception' => ['sometimes', 'boolean']])->validate();
        $close = CashBoxClose::findOrFail($closeId);
        $data['reason'] = trim($data['reason'] ?? '') ?: ($close->user_id === $actor->id
            ? 'Autorización excepcional del propio cierre; el faltante se conserva.'
            : 'Cierre con faltante autorizado por Administración; la deuda se conserva.');
        $id = app(CashBoxCommand::class)->execute($actor, $close->cash_box_session_id, false, 'approve_closing_v3', $data, function (User $admin, $session) use ($closeId, $data): int {
            $close = CashBoxClose::whereKey($closeId)->lockForUpdate()->firstOrFail();
            if ($close->user_id === $admin->id && ! ($data['exception'] ?? false)) {
                throw ValidationException::withMessages(['exception' => 'Marca la confirmación excepcional para autorizar tu propio cierre.']);
            }
            app(AdministrativeReview::class)->authorize($admin, 'cajas.liberar', $close->user_id, (bool) ($data['exception'] ?? false), $data['reason']);
            if ($close->workflow_version !== 3 || $close->cancelled_at || $close->released_at || $session->status !== 'CIERRE_REGISTRADO' || ! $close->liquidation_completed) {
                throw ValidationException::withMessages(['reason' => 'Este cierre no está pendiente de confirmación o no completó su liquidación.']);
            }
            if ($close->requires_liquidation && $close->operatingTotal() < $close->capital_cents) {
                throw ValidationException::withMessages(['reason' => 'Falta capital real. No se puede liberar inventando saldos: primero debe registrarse la reposición correspondiente.']);
            }
            if ($close->requires_liquidation && $close->operatingTotal() > $close->capital_cents) {
                throw ValidationException::withMessages(['reason' => 'Hay excedente real pendiente de liquidar después de la reposición.']);
            }
            $now = now();
            $close->update(['reviewed_by' => $admin->id, 'review_reason' => $data['reason'], 'closure_approved_at' => $now,
                'released_by' => $admin->id, 'released_at' => $now, 'release_reason' => $data['reason']]);
            $session->update(['status' => 'CERRADA_CON_INCIDENCIAS', 'active_slot' => null, 'released_at' => $now,
                'handover_balances' => $close->real_balances, 'revision' => $session->revision + 1]);
            CashBoxAudit::create(['cash_box_id' => $session->cash_box_id, 'actor_user_id' => $admin->id, 'request_key' => $data['request_key'],
                'action' => 'CIERRE_APROBADO', 'payload_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                'before' => ['status' => 'CIERRE_REGISTRADO'], 'after' => ['close_id' => $close->id, 'status' => $session->status], 'reason' => $data['reason'], 'created_at' => $now]);

            return $close->id;
        });

        return CashBoxClose::findOrFail($id);
    }
}
