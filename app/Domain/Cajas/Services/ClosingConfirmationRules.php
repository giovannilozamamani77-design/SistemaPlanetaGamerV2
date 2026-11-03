<?php

namespace App\Domain\Cajas\Services;

use App\Domain\Arqueos\Calculators\CashCountCalculator;
use App\Domain\Cajas\Calculators\CashBoxClosingCounterPreview;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Queries\CashBoxClosingAccessQuery;
use App\Domain\Cajas\Queries\CashBoxClosingCounterReferenceQuery;
use App\Domain\Cajas\Queries\CashBoxClosingStartQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal: caller holds user, box and session locks in the financial transaction. */
class ClosingConfirmationRules
{
    /** Read-only preflight; final confirmation repeats every check under locks. */
    public function preview(User $actor, int $sessionId, array $data): array
    {
        app(CashBoxClosingStartQuery::class)->execute($actor, $sessionId);
        try {
            $this->evaluate($actor, CashBoxSession::findOrFail($sessionId), $data);

            return ['ready' => true, 'message' => null];
        } catch (ValidationException $exception) {
            return ['ready' => false, 'message' => $exception->validator->errors()->first()];
        }
    }

    public function evaluate(User $actor, CashBoxSession $session, array $data): array
    {
        if ($session->status !== 'ABIERTA' || $session->active_slot === null || CashBoxClose::where('cash_box_session_id', $session->id)->whereNull('cancelled_at')->exists()) {
            $this->fail('Esta sesión ya tiene un cierre o no está abierta. Consulta el historial.');
        }
        if ($session->revision !== (int) $data['expected_revision']) {
            $this->fail('La caja cambió desde la revisión. Vuelve al resumen y verifica los saldos antes de confirmar.');
        }
        if (DB::table('cash_box_corrections')->where('cash_box_session_id', $session->id)->whereIn('status', ['PENDIENTE', 'DEVUELTA'])->exists()) {
            $this->fail('Resuelve las solicitudes de corrección pendientes o devueltas antes de cerrar.');
        }
        $context = app(CashBoxClosingStartQuery::class)->execute($actor, $session->id, true);
        $shift = $context['shifts'][$data['period']] ?? null;
        if (! $shift) {
            $this->fail('El turno no pertenece a esta sesión.');
        }
        $timeGate = app(CashBoxClosingAccessQuery::class)->assertAllowed($actor, $session->id, $data['period']);
        $early = $timeGate['early'] ? $timeGate['request'] : null;
        $earlyWorkdayId = $timeGate['workday_id'];
        $assessment = app(ClosingFinancialAssessment::class)->calculate($actor, $session->id, $data, $context);
        $counted = $assessment['counted'];
        $restored = $assessment['restored'];
        $reserved = $assessment['reserved'];
        $liquidation = $assessment['liquidation'];
        $references = $context['family'] === 'BCP' ? app(CashBoxClosingCounterReferenceQuery::class)->forBox($session->cash_box_id) : null;
        $counters = $references ? app(CashBoxClosingCounterPreview::class)->calculate($data['readings'], $references) : null;
        if ($counters && ! $counters['valid']) {
            $this->fail('Los contadores cambiaron o son inválidos. Revisa las lecturas.');
        }
        $count = null;
        if (isset($data['cash_count'])) {
            $result = app(CashCountCalculator::class)->recalculateSnapshot($data['cash_count']);
            if (! $result['valid'] || $result['snapshot']['total_cents'] !== ($counted['component_cents']['efectivo'] ?? null)) {
                $this->fail('El arqueo no coincide con el efectivo final. Revísalo o usa el importe manual.');
            }
            $count = $result['snapshot'];
        }
        if ($shift['liquidates'] && $liquidation['shortage_cents'] > 0) {
            $this->fail('Falta reponer capital operativo real antes de liquidar y finalizar. Registra el aporte recibido en el resumen.');
        }
        $source = $context['settlement_source'] ?? '';
        $sourceAccount = $context['accounts'][$source] ?? null;
        $category = $sourceAccount['category'] ?? ($source === 'bcp' ? 'banco' : ($source === 'yape' ? 'billetera' : ''));
        if ($shift['liquidates'] && (! $sourceAccount || ! ($sourceAccount['is_active'] ?? true) || ! in_array($category, ['banco', 'billetera', 'tigo_money'], true))) {
            $this->fail('La cuenta de origen no es una cuenta bancaria o billetera activa de esta sesión.');
        }
        if ((bool) $data['liquidate'] !== $shift['liquidates']) {
            $this->fail('Revisa la liquidación requerida por el turno seleccionado.');
        }
        if ($shift['liquidates'] && $liquidation['amount_cents'] > 0 && ! $liquidation['can_liquidate']) {
            $this->fail('La cuenta de origen no cubre el importe de liquidación. No se puede descontar de otra cuenta.');
        }
        if ($counted['difference_cents'] < 0 && mb_strlen(trim($data['reason'] ?? '')) < 3) {
            $this->fail('Describe el faltante para que Administración pueda revisarlo.');
        }

        return compact('context', 'shift', 'counted', 'references', 'counters', 'count', 'reserved', 'liquidation', 'early', 'earlyWorkdayId', 'restored');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['closing' => $message]);
    }
}
