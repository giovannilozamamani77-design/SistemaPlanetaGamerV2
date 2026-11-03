<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeclareRecoveryAction
{
    /** @param array<string,mixed> $data */
    public function execute(User $actor, EconomicIncident $target, array $data): RecoveryPayment
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('incidencias.declarar_aporte');
        if ($user->id !== $target->user_id && ! $user->hasRole('administrador')) {
            throw new AuthorizationException;
        }
        $v = Validator::make($data, [
            'paid_by_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'request_key' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['RECUPERACION', 'APORTE_ADMINISTRATIVO'])],
            'method' => ['required', Rule::in(['EFECTIVO', 'DEPOSITO', 'QR', 'SUELDO'])],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:99999999900'],
            'operation_reference' => ['nullable', 'string', 'max:255'], 'destination' => ['nullable', 'string', 'max:255'],
            'salary_period' => ['nullable', 'date_format:Y-m'], 'note' => ['required', 'string', 'min:3', 'max:2000'],
        ])->validate();
        if ($v['kind'] === 'APORTE_ADMINISTRATIVO' && ! $user->hasRole('administrador')) {
            throw new AuthorizationException;
        }

        if (in_array($v['method'], ['DEPOSITO', 'QR'], true)) {
            $v['operation_reference'] = isset($v['operation_reference']) ? mb_strtoupper(trim($v['operation_reference'])) : null;
            $v['destination'] = isset($v['destination']) ? trim($v['destination']) : null;
        }

        return DB::transaction(function () use ($user, $target, $v): RecoveryPayment {
            User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            if ($existing = RecoveryPayment::where('request_key', $v['request_key'])->first()) {
                if ($existing->economic_incident_id !== $target->id) {
                    throw new AuthorizationException;
                }

                return $existing;
            }
            $case = EconomicIncident::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($case->pending_cents <= 0) {
                throw ValidationException::withMessages(['amount' => 'No hay importe confirmado pendiente.']);
            }
            if ($v['kind'] === 'RECUPERACION' && $v['amount_cents'] > $case->pending_cents) {
                throw ValidationException::withMessages(['amount' => 'El aporte supera el saldo pendiente.']);
            }
            if (in_array($v['method'], ['DEPOSITO', 'QR'], true) && filled($v['operation_reference'] ?? null) && filled($v['destination'] ?? null)) {
                if (GlobalRecovery::where('destination', $v['destination'])->whereRaw('UPPER(TRIM(operation_reference)) = ?', [$v['operation_reference']])->exists()) {
                    throw ValidationException::withMessages(['operation_reference' => 'Esta operación ya pertenece a una reposición global.']);
                }
                $prior = RecoveryPayment::where('operation_reference', $v['operation_reference'])->where('destination', $v['destination'])->first();
                if ($prior) {
                    if ($prior->economic_incident_id !== $case->id) {
                        throw ValidationException::withMessages(['operation_reference' => 'Esta operación ya está vinculada a otra incidencia.']);
                    }

                    return $prior;
                }
            }
            $payer = $v['kind'] === 'APORTE_ADMINISTRATIVO' ? $user->id : ($v['paid_by_user_id'] ?? $case->user_id);
            if (! $user->hasRole('administrador') && $payer !== $user->id) {
                throw new AuthorizationException;
            }
            $v['paid_by_user_id'] = $payer;
            if ($v['kind'] === 'RECUPERACION' && $payer !== $case->user_id && User::findOrFail($payer)->hasRole('administrador')) {
                throw ValidationException::withMessages(['amount' => 'Los fondos de Administración se registran como aporte administrativo y no cancelan la deuda personal.']);
            }
            $payment = RecoveryPayment::create(array_merge($v, ['economic_incident_id' => $case->id, 'declared_by' => $user->id]));
            app(EconomicHistoryAudit::class)->record($case->id, $user->id, 'APORTE_DECLARADO', 'declare:'.$payment->id, ['payment_id' => $payment->id, 'amount_cents' => $payment->amount_cents, 'kind' => $payment->kind, 'method' => $payment->method]);
            if ($payment->method === 'SUELDO' && $payment->kind === 'RECUPERACION') {
                $case->update(['status' => 'DESCUENTO_PROGRAMADO']);
            }

            return $payment;
        });
    }
}
