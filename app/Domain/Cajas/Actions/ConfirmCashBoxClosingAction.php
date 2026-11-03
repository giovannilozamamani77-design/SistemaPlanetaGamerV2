<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Authorization\CashBoxCommand;
use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Models\CashBoxAudit;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Services\ClosingConfirmationRules;
use App\Domain\ControlHorario\Actions\CaptureCashBoxEarlyWorkAction;
use App\Domain\ControlHorario\Actions\ProcessCashBoxScheduleEventAction;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\Incidencias\Actions\ReportEconomicIncidentAction;
use App\Domain\Liquidaciones\Actions\RecordClosingLiquidationAction;
use App\Domain\Notificaciones\Actions\RecordCashBoxTelegramNoticeAction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConfirmCashBoxClosingAction
{
    public function execute(User $actor, int $sessionId, array $data): CashBoxClose
    {
        Validator::make($data, [
            'period' => ['required', Rule::in(['manana', 'tarde'])], 'balances' => ['required', 'array'], 'readings' => ['present', 'array'],
            'expected_revision' => ['required', 'integer', 'min:0'], 'liquidate' => ['required', 'boolean'],
            'account_id' => ['nullable', 'integer'], 'amount_cents' => ['nullable', 'integer', 'min:0'], 'transferred' => ['nullable', 'boolean'],
            'operation_reference' => ['nullable', 'string', 'max:120'], 'reason' => ['nullable', 'string', 'max:2000'], 'cash_count' => ['nullable', 'array'],
            'cash_count.quantities' => ['sometimes', 'array'], 'cash_count.packed_amounts_cents' => ['sometimes', 'array'],
            'request_key' => ['required', 'uuid'], 'restoration_token' => ['nullable', 'string', 'max:16000'],
        ])->validate();
        $data['operation_reference'] = trim($data['operation_reference'] ?? '');
        $candidate = CashBoxSession::findOrFail($sessionId);
        if ($candidate->status === 'ABIERTA') {
            app(PrepareCashBoxClosingAccessAction::class)->execute($actor, $sessionId, $data['period']);
        }

        try {
            $id = app(CashBoxCommand::class)->execute($actor, $sessionId, true, 'confirm_closing_v3', $data, function (User $user, $session) use ($data): int {
                $r = app(ClosingConfirmationRules::class)->evaluate($user, $session, $data);
                $now = now('UTC')->toImmutable();
                $shortage = $r['counted']['difference_cents'] < 0;
                $close = CashBoxClose::create(['cash_box_session_id' => $session->id, 'cash_box_id' => $session->cash_box_id, 'user_id' => $user->id,
                    'workflow_version' => 3, 'period' => $data['period'], 'shift_snapshot' => $r['shift'],
                    'counted_balances' => $r['counted']['component_cents'], 'real_balances' => $r['restored']['balances'],
                    'expected_balances' => $r['context']['expected_balances'], 'comparison' => $r['counted'], 'difference_cents' => $r['counted']['difference_cents'],
                    'commission_cents' => $r['context']['commission_total'], 'capital_cents' => $r['context']['operating_capital'],
                    'requires_liquidation' => $r['shift']['liquidates'], 'liquidation_completed' => true,
                    'settlement_source' => $r['context']['settlement_source'] ?? '', 'reason' => $data['reason'] ?? null,
                    'provisional_surplus_cents' => max(0, $r['counted']['difference_cents']),
                    'unavailable_commission_cents' => min(max(0, -$r['counted']['difference_cents']), $r['context']['commission_total']),
                    'reserved_funds_snapshot' => $r['reserved'],
                    'temporary_funds_snapshot' => DB::table('cash_box_temporary_funds')->where('cash_box_id', $session->cash_box_id)->where('remaining_cents', '>', 0)->orderBy('id')->get()->toArray(),
                    'count_details' => ['method' => $r['count'] ? 'ARQUEO' : 'MANUAL', 'cash_count' => $r['count']], 'created_at' => $now]);
                if ($grant = $r['restored']['restoration']) {
                    DB::table('cash_box_capital_restorations')->insert(['cash_box_close_id' => $close->id, 'actor_user_id' => $grant['admin'],
                        'kind' => 'APORTE_ADMINISTRATIVO', 'component' => $grant['component'], 'amount_cents' => $grant['amount_cents'], 'receipt' => $grant['receipt'], 'created_at' => $now]);
                    CashBoxAudit::create(['cash_box_id' => $session->cash_box_id, 'actor_user_id' => $grant['admin'], 'request_key' => (string) Str::uuid(),
                        'action' => 'APORTE_CIERRE', 'payload_hash' => hash('sha256', json_encode($grant, JSON_THROW_ON_ERROR)),
                        'before' => ['counted' => $r['counted']['component_cents']], 'after' => ['close_id' => $close->id, 'balances' => $r['restored']['balances']],
                        'reason' => $grant['receipt'], 'created_at' => $now]);
                }
                if ($r['shift']['liquidates']) {
                    app(RecordClosingLiquidationAction::class)->execute($user, $close, $r['liquidation'], $data);
                }
                if ($shortage) {
                    $incident = app(ReportEconomicIncidentAction::class)->execute($user, $user, ['source_module' => 'cajas', 'source_reference' => 'close:'.$close->id,
                        'component' => 'total', 'amount_cents' => -$r['counted']['difference_cents'], 'reason' => $data['reason'], 'occurred_at' => $now->toIso8601String(), 'box_label' => $r['context']['box']]);
                    $close->economic_incident_id = $incident->id;
                }
                if ($r['counters']) {
                    DB::table('cash_box_counter_readings')->insert(['cash_box_id' => $session->cash_box_id, 'cash_box_close_id' => $close->id, 'user_id' => $user->id,
                        'month' => $r['references']['month'], 'reading' => $r['counters']['counters']['general']['current'], 'delta' => $r['counters']['counters']['general']['delta'] ?? 0,
                        'fixed_reading' => $r['counters']['counters']['fixed']['current'], 'fixed_delta' => $r['counters']['counters']['fixed']['delta'],
                        'baseline' => $r['counters']['counters']['general']['reference'] === null, 'created_at' => $now]);
                }
                if ($r['early'] && $r['earlyWorkdayId']) {
                    $access = CashBoxAccess::where('cash_box_session_id', $session->id)->where('user_id', $user->id)->oldest('id')->firstOrFail();
                    CashBoxScheduleEvent::firstOrCreate(['source_key' => 'closing:'.$close->id],
                        ['kind' => 'CLOSING', 'user_id' => $user->id, 'pin_audit_id' => $access->pin_audit_id, 'access_id' => $access->id,
                            'scheduled_workday_id' => $r['earlyWorkdayId'], 'occurred_at' => $now]);
                }
                $before = ['status' => $session->status, 'revision' => $session->revision, 'balances' => $session->current_balances];
                $session->update(['status' => $shortage ? 'CIERRE_REGISTRADO' : 'CERRADA', 'closed_at' => $now,
                    'active_slot' => $shortage ? 1 : null, 'released_at' => $shortage ? null : $now,
                    'current_balances' => $close->real_balances, 'handover_balances' => $shortage ? null : $close->real_balances, 'revision' => $session->revision + 1]);
                if (! $shortage) {
                    $close->closure_approved_at = $now;
                    $close->released_at = $now;
                    $close->release_reason = 'Continuidad automática: cierre sin faltante y liquidación cumplida.';
                }
                $close->save();
                app(CaptureCashBoxEarlyWorkAction::class)->closing($close, $session);
                CashBoxAudit::create(['cash_box_id' => $session->cash_box_id, 'actor_user_id' => $user->id, 'request_key' => $data['request_key'],
                    'action' => 'CIERRE_CONFIRMADO', 'payload_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 'before' => $before,
                    'after' => ['close_id' => $close->id, 'status' => $session->status, 'balances' => $session->current_balances, 'liquidated_cents' => $close->liquidated_cents],
                    'reason' => $shortage ? $data['reason'] : 'Cierre confirmado por su titular.', 'created_at' => $now]);

                app(RecordCashBoxTelegramNoticeAction::class)->closing($close->id);

                return $close->id;
            });

        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['closing' => 'La solicitud o referencia ya fue registrada. Revisa el historial antes de repetirla.']);
        }

        $event = CashBoxScheduleEvent::where('source_key', 'closing:'.$id)->first();
        if ($event) {
            app(ProcessCashBoxScheduleEventAction::class)->safely($event->id);
        }

        $result = CashBoxClose::findOrFail($id);
        app(CaptureCashBoxEarlyWorkAction::class)->safelyForSession($result->cash_box_session_id);

        return $result;
    }
}
