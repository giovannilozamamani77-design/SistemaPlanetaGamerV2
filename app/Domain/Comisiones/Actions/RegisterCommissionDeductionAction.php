<?php

namespace App\Domain\Comisiones\Actions;

use App\Domain\Accesorios\Data\PricingInput;
use App\Domain\Comisiones\Models\CommissionDeduction;
use App\Domain\Comisiones\Queries\CommissionDeductionQuery;
use App\Domain\Comisiones\Queries\CommissionPaymentQuery;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RegisterCommissionDeductionAction
{
    public function execute(User $actor, array $data): CommissionDeduction
    {
        app(CommissionDeductionQuery::class)->authorize($actor);
        if (is_string($data['reason'] ?? null)) {
            $data['reason'] = trim($data['reason']);
        }
        $v = Validator::make($data, [
            'user_id' => ['required', 'integer', 'min:1'], 'time_incident_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required'], 'reason' => ['required', 'string', 'max:500'],
            'confirmed' => ['required', 'accepted'], 'request_key' => ['required', 'uuid'],
            'expected_pending_cents' => ['required', 'integer', 'min:0'],
        ])->validate();
        $amount = PricingInput::money($v['amount'], 'amount');
        $hash = hash('sha256', json_encode([(int) $v['user_id'], (int) $v['time_incident_id'], $amount, $v['reason']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $v, $amount, $hash): CommissionDeduction {
            $employee = User::whereKey($v['user_id'])->lockForUpdate()->firstOrFail();
            app(CommissionDeductionQuery::class)->authorize($actor);
            if ($old = CommissionDeduction::where('request_key', $v['request_key'])->first()) {
                if ($old->user_id !== $employee->id || $old->registered_by_user_id !== $actor->id) {
                    throw new AuthorizationException;
                }
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['amount' => 'Este envío ya registró un descuento con otros datos. Revisa el historial.']);
                }

                return $old;
            }
            $incident = TimeIncident::where('user_id', $employee->id)->whereKey($v['time_incident_id'])->lockForUpdate()->first();
            if (! $incident || ! in_array($incident->kind, ['RETRASO', 'FALTA', 'FALTA_PARCIAL'], true) || $incident->status !== 'CONFIRMADA' || $incident->pending_minutes <= 0) {
                throw ValidationException::withMessages(['time_incident_id' => 'Selecciona una falta o atraso confirmado y pendiente de esta persona.']);
            }
            if (CommissionDeduction::where('time_incident_id', $incident->id)->exists()) {
                throw ValidationException::withMessages(['time_incident_id' => 'Esta incidencia ya tiene un descuento registrado.']);
            }
            $balance = app(CommissionPaymentQuery::class)->balance($actor, $employee->id);
            if ($balance['pending_cents'] !== (int) $v['expected_pending_cents']) {
                throw ValidationException::withMessages(['amount' => 'Las comisiones disponibles cambiaron. Cierra la ventana y revisa el saldo.']);
            }
            if ($amount > $balance['pending_cents']) {
                throw ValidationException::withMessages(['amount' => 'El descuento supera las comisiones pendientes.']);
            }

            return CommissionDeduction::create([
                'user_id' => $employee->id, 'time_incident_id' => $incident->id, 'employee_name' => $employee->name,
                'registered_by_user_id' => $actor->id, 'registered_by_name' => $actor->fresh()->name,
                'amount_cents' => $amount, 'before_pending_cents' => $balance['pending_cents'], 'after_pending_cents' => $balance['pending_cents'] - $amount,
                'reason' => $v['reason'], 'snapshot' => ['earned_cents' => $balance['earned_cents'], 'paid_cents' => $balance['paid_cents'], 'incident_kind' => $incident->kind, 'incident_status' => $incident->status, 'real_minutes' => $incident->real_minutes, 'nominal_minutes' => $incident->nominal_minutes, 'pending_minutes' => $incident->pending_minutes, 'scheduled_workday_id' => $incident->scheduled_workday_id],
                'request_key' => $v['request_key'], 'payload_hash' => $hash, 'created_at' => now(),
            ]);
        }, 3);
    }
}
