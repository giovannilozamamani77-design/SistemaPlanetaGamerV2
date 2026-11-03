<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Usuarios\Calculators\SalaryPeriodCalculator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportEconomicIncidentAction
{
    /** @param array<string,mixed> $data */
    public function execute(User $actor, User $responsible, array $data): EconomicIncident
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('incidencias.reportar');
        if ($user->id !== $responsible->id && ! $user->hasRole('administrador')) {
            throw new AuthorizationException;
        }
        $v = Validator::make($data, [
            'source_module' => ['required', Rule::in(['manual', 'cajas', 'tarjetas', 'prestamos', 'accesorios'])],
            'source_reference' => ['required', 'string', 'max:160'], 'component' => ['required', 'string', 'max:48'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:99999999900'],
            'reason' => [Rule::requiredIf(($data['amount_cents'] ?? 0) > 200), 'nullable', 'string', 'min:3', 'max:2000'], 'occurred_at' => ['required', 'date'], 'box_label' => ['nullable', 'string', 'max:120'], 'cause' => ['sometimes', Rule::in(['CAJERA', 'CARGO_BANCARIO'])],
        ])->validate();

        if ($v['source_module'] === 'tarjetas' && preg_match('/\Acard-(?:open|close):/', $v['source_reference'])) {
            throw ValidationException::withMessages(['source_reference' => 'El origen de conteos de Tarjetas se genera automáticamente al abrir o cerrar.']);
        }
        $v['cause'] ??= 'CAJERA';
        $v['reason'] = filled($v['reason'] ?? null) ? $v['reason'] : 'Diferencia menor para revisión';
        if ($v['cause'] === 'CARGO_BANCARIO' && ! in_array(mb_strtolower(trim($v['component'])), ['bcp', 'cuenta_bcp'], true)) {
            throw ValidationException::withMessages(['cause' => 'Solo cuenta BCP admite posible cargo bancario.']);
        }

        return DB::transaction(function () use ($user, $responsible, $v): EconomicIncident {
            $owner = User::whereKey($responsible->id)->lockForUpdate()->firstOrFail();
            $period = app(SalaryPeriodCalculator::class)->forUser($owner, CarbonImmutable::parse($v['occurred_at']));
            $v['period_start'] = $period['start'] ?? null;
            $v['period_end'] = $period['end'] ?? null;
            $existing = EconomicIncident::where('source_module', $v['source_module'])->where('source_reference', $v['source_reference'])->where('component', $v['component'])->first();
            if ($existing) {
                if ($existing->user_id !== $user->id && ! $user->hasRole('administrador')) {
                    throw new AuthorizationException;
                }

                return $existing;
            }
            $automatic = $v['source_module'] === 'cajas' && str_starts_with($v['source_reference'], 'close:');
            $case = EconomicIncident::create(array_merge($v, ['user_id' => $responsible->id, 'reported_by' => $user->id, 'status' => $automatic ? 'CONFIRMADA' : 'DETECTADA', 'pending_cents' => $automatic ? $v['amount_cents'] : 0, 'policy_snapshot' => app(PersonnelRules::class)->current()]));
            app(EconomicHistoryAudit::class)->record($case->id, $user->id, 'FALTANTE_REPORTADO', 'report:'.$case->id, ['amount_cents' => $case->amount_cents, 'source_module' => $case->source_module, 'reference' => $case->source_reference, 'component' => $case->component]);

            return $case;
        });
    }
}
