<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\Incidencias\Actions\EconomicHistoryAudit;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Usuarios\Calculators\SalaryPeriodCalculator;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Internal writer: OpenCashBoxAction owns the transaction and ordered custody locks. */
class RecordCashBoxReceiptAction
{
    public function execute(User $actor, CashBoxSession $session): void
    {
        if (DB::transactionLevel() === 0 || $session->user_id !== $actor->id || $session->status !== 'ABIERTA') {
            throw new LogicException('La recepción se registra únicamente dentro de la apertura de su titular.');
        }
        Gate::forUser(User::findOrFail($actor->id))->authorize('cajas.operar');
        $difference = (int) $session->comparison['difference_cents'];
        if ($difference === 0 || DB::table('cash_box_receipt_discrepancies')->where('cash_box_session_id', $session->id)->exists()) {
            return;
        }
        $previous = $session->previous_session_id ? CashBoxSession::findOrFail($session->previous_session_id) : null;
        if ($previous && ($previous->cash_box_id !== $session->cash_box_id || ! $previous->released_at)) {
            throw new LogicException('La entrega anterior no corresponde a esta apertura.');
        }
        $classification = $difference > 0 ? 'SOBRANTE' : ($previous ? 'FALTANTE' : 'SIN_CUSTODIO');
        $case = null;
        if ($difference < 0 && $previous) {
            $owner = User::findOrFail($previous->user_id);
            $period = app(SalaryPeriodCalculator::class)->forUser($owner, $session->opened_at);
            $changed = array_filter($session->comparison['components'], fn (array $component): bool => $component['difference'] !== 0);
            $component = count($changed) === 1 ? array_key_first($changed) : 'total';
            $case = EconomicIncident::create(['user_id' => $owner->id, 'reported_by' => $actor->id, 'source_module' => 'cajas',
                'source_reference' => 'receipt:'.$session->id, 'component' => $component, 'status' => 'CONFIRMADA',
                'amount_cents' => -$difference, 'pending_cents' => -$difference, 'reason' => $session->comparison['reason'],
                'box_label' => $session->box->name, 'cause' => 'CAJERA', 'occurred_at' => $session->opened_at,
                'period_start' => $period['start'] ?? null, 'period_end' => $period['end'] ?? null,
                'policy_snapshot' => [...app(PersonnelRules::class)->current(), 'automatic_reception_debt' => true, 'previous_session_id' => $previous->id]]);
            app(EconomicHistoryAudit::class)->record($case->id, $actor->id, 'FALTANTE_RECEPCION_AUTOMATICO', 'receipt:'.$session->id,
                ['session_id' => $session->id, 'previous_session_id' => $previous->id, 'amount_cents' => -$difference]);
        }
        DB::table('cash_box_receipt_discrepancies')->insert(['cash_box_session_id' => $session->id, 'previous_session_id' => $previous?->id,
            'reported_by' => $actor->id, 'difference_cents' => $difference, 'comparison' => json_encode($session->comparison, JSON_THROW_ON_ERROR),
            'reason' => $session->comparison['reason'], 'status' => $case ? 'DEUDA_REGISTRADA' : ($difference > 0 ? 'SOBRANTE_REGISTRADO' : 'EN_REVISION'),
            'classification' => $classification, 'economic_incident_id' => $case?->id, 'created_at' => now()]);
        $session->update(['comparison' => [...$session->comparison, 'receipt_classification' => $classification, 'opening_surplus_cents' => max(0, $difference)]]);
    }
}
