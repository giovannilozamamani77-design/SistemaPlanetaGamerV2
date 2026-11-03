<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Usuarios\Authorization\AdministrativeReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeclareGlobalRecoveryAction
{
    public function execute(User $actor, User $target, array $data): GlobalRecovery
    {
        $actor = User::findOrFail($actor->id);
        Gate::forUser($actor)->authorize('incidencias.declarar_aporte');
        if ($actor->id !== $target->id) {
            app(AdministrativeReview::class)->authorize($actor, 'incidencias.reposiciones.confirmar');
        }
        $v = Validator::make($data, [
            'request_key' => ['required', 'uuid'], 'method' => ['required', Rule::in(['EFECTIVO', 'DEPOSITO', 'QR', 'SUELDO'])],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:99999999900'], 'note' => ['required', 'string', 'max:2000'],
            'operation_reference' => ['nullable', 'string', 'max:255'], 'destination' => ['nullable', 'string', 'max:255'],
            'salary_period' => ['nullable', 'date_format:Y-m'],
        ])->validate();

        if (in_array($v['method'], ['DEPOSITO', 'QR'], true)) {
            $v['operation_reference'] = isset($v['operation_reference']) ? mb_strtoupper(trim($v['operation_reference'])) : null;
            $v['destination'] = isset($v['destination']) ? trim($v['destination']) : null;
        }

        return DB::transaction(function () use ($actor, $target, $v): GlobalRecovery {
            User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($prior = GlobalRecovery::where('request_key', $v['request_key'])->first()) {
                if ($prior->user_id !== $target->id || $prior->declared_by !== $actor->id || $prior->amount_cents !== (int) $v['amount_cents'] || $prior->method !== $v['method'] || $prior->operation_reference !== ($v['operation_reference'] ?? null) || $prior->destination !== ($v['destination'] ?? null)) {
                    throw ValidationException::withMessages(['request_key' => 'Referencia usada por otra declaración.']);
                }

                return $prior;
            }
            if (GlobalRecovery::where('user_id', $target->id)->whereNull('confirmed_at')->whereNull('rejected_at')->exists() || RecoveryPayment::whereHas('economicIncident', fn ($q) => $q->where('user_id', $target->id))->where('kind', 'RECUPERACION')->whereNull('confirmed_at')->whereNull('rejected_at')->exists()) {
                throw ValidationException::withMessages(['amount' => 'Ya existe una reposición por verificar; revisa esa solicitud antes de informar otra global.']);
            }
            $pending = (int) EconomicIncident::where('user_id', $target->id)->whereIn('status', ['CONFIRMADA', 'PAGO_PARCIAL', 'DESCUENTO_PROGRAMADO'])->sum('pending_cents');
            if ($v['amount_cents'] > $pending) {
                throw ValidationException::withMessages(['amount' => 'El importe supera la deuda confirmada pendiente.']);
            }
            if (in_array($v['method'], ['DEPOSITO', 'QR'], true)) {
                if (empty($v['operation_reference']) || empty($v['destination'])) {
                    throw ValidationException::withMessages(['operation_reference' => 'Registra operación y destino reales.']);
                }
                if (RecoveryPayment::where('destination', $v['destination'])->whereRaw('UPPER(TRIM(operation_reference)) = ?', [$v['operation_reference']])->exists()
                 || GlobalRecovery::where('destination', $v['destination'])->whereRaw('UPPER(TRIM(operation_reference)) = ?', [$v['operation_reference']])->exists()
                 || DB::table('cash_box_liquidations')->where('destination', $v['destination'])->whereRaw('UPPER(TRIM(operation_reference)) = ?', [$v['operation_reference']])->exists()) {
                    throw ValidationException::withMessages(['operation_reference' => 'La operación ya fue registrada.']);
                }
            }
            if ($v['method'] === 'SUELDO' && empty($v['salary_period'])) {
                throw ValidationException::withMessages(['salary_period' => 'Registra periodo salarial.']);
            }
            $batch = GlobalRecovery::create(array_merge($v, ['user_id' => $target->id, 'declared_by' => $actor->id]));
            DB::table('user_audits')->insert(['actor_user_id' => $actor->id, 'subject_user_id' => $target->id, 'action' => 'global_recovery_declared', 'changes' => json_encode(['recovery_id' => $batch->id, 'amount_cents' => $batch->amount_cents], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $batch;
        });
    }
}
