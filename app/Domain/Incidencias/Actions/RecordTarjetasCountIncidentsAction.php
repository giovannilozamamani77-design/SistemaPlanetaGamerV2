<?php

namespace App\Domain\Incidencias\Actions;

use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Tarjetas\Authorization\CardInventoryAuthorization;
use App\Domain\Tarjetas\Models\CardStoreCount;
use App\Domain\Usuarios\Calculators\SalaryPeriodCalculator;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class RecordTarjetasCountIncidentsAction
{
    public function execute(User $actor, CardStoreCount $count): array
    {
        app(CardInventoryAuthorization::class)->authorize($actor, $count->kind === 'OPEN' ? 'openSession' : 'closeSession');
        if ($actor->id !== $count->user_id) {
            throw new AuthorizationException;
        }
        if (! $count->responsible_user_id) {
            return [];
        }
        $owner = User::findOrFail($count->responsible_user_id);
        $period = app(SalaryPeriodCalculator::class)->forUser($owner, $count->created_at);
        $components = [];
        $cash = max(0, $count->cash_expected_cents - $count->cash_counted_cents);
        if ($cash) {
            $components['efectivo'] = ['amount' => $cash, 'reason' => 'Faltante de efectivo de Tarjetas'];
        }
        foreach ($count->lines as $line) {
            if ($line['shortage_cents']) {
                $components['tarjeta:'.$line['product_id']] = ['amount' => $line['shortage_cents'], 'reason' => $line['company'].' '.$line['label'].': '.$line['missing'].' unidades faltantes al valor facial'];
            }
        }
        $ids = [];
        $reference = 'card-'.strtolower($count->kind).':'.$count->id;
        foreach ($components as $component => $value) {
            if ($value['amount'] > 99999999900) {
                throw ValidationException::withMessages(['stocks' => 'La incidencia supera el máximo admitido.']);
            }
            $existing = EconomicIncident::where('source_module', 'tarjetas')->where('source_reference', $reference)->where('component', $component)->first();
            if ($existing) {
                if ($existing->user_id !== $owner->id || $existing->amount_cents !== $value['amount']) {
                    throw ValidationException::withMessages(['stocks' => 'El origen de esta incidencia ya existe con otros datos.']);
                }$ids[] = $existing->id;

                continue;
            }
            $case = EconomicIncident::create(['user_id' => $owner->id, 'reported_by' => $count->user_id, 'source_module' => 'tarjetas', 'source_reference' => $reference, 'component' => $component, 'status' => 'CONFIRMADA', 'amount_cents' => $value['amount'], 'pending_cents' => $value['amount'], 'policy_snapshot' => app(PersonnelRules::class)->current(), 'reason' => $value['reason'], 'box_label' => 'Tienda de Tarjetas · sesión #'.$count->card_store_session_id, 'cause' => 'CAJERA', 'occurred_at' => $count->created_at, 'period_start' => $period['start'] ?? null, 'period_end' => $period['end'] ?? null]);
            app(EconomicHistoryAudit::class)->record($case->id, $actor->id, 'FALTANTE_TARJETAS', 'card-count:'.$count->id.':'.$component, ['count_id' => $count->id, 'origin' => $count->kind, 'responsible_user_id' => $owner->id, 'amount_cents' => $value['amount']]);
            $ids[] = $case->id;
        }

        return $ids;
    }
}
