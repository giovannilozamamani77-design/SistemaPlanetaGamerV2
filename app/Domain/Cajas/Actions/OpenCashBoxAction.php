<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Arqueos\Calculators\CashCountCalculator;
use App\Domain\Cajas\Contracts\ReservedCashBoxFunds;
use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\Cajas\Queries\CashBoxOperatingCapital;
use App\Domain\Cajas\Services\CashBoxScheduleSafety;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\ControlHorario\Actions\ProcessCashBoxScheduleEventAction;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Domain\Notificaciones\Actions\RecordCashBoxTelegramNoticeAction;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OpenCashBoxAction
{
    public function __construct(private PersonnelRules $rules) {}

    public function execute(User $actor, int $accessId, array $data): CashBoxSession
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('cajas.operar');
        $access = CashBoxAccess::findOrFail($accessId);
        if ($access->user_id !== $user->id) {
            throw new AuthorizationException;
        }
        $v = Validator::make($data, ['balances' => ['required', 'array:'.implode(',', array_keys($access->expected_balances))],
            'second_balances' => ['sometimes', 'array:'.implode(',', array_keys($access->expected_balances))],
            'cash_count' => ['nullable', 'array'], 'cash_count.quantities' => ['sometimes', 'array'], 'cash_count.packed_amounts_cents' => ['sometimes', 'array'],
            'use_cash_count' => ['prohibited'], 'cash_counts' => ['prohibited'], 'packed_amounts' => ['prohibited'],
            'reason' => ['nullable', 'string', 'max:2000'], 'request_key' => ['required', 'uuid'],
            'user_id' => ['prohibited'], 'turn' => ['prohibited'], 'period' => ['prohibited'], 'opened_at' => ['prohibited']])->validate();
        $balances = [];
        $second = [];
        foreach ($access->expected_balances as $code => $expected) {
            foreach (['balances', 'second_balances'] as $group) {
                $raw = $data[$group][$code] ?? '';
                $amount = $raw === '' || $raw === null ? '0' : $raw;
                Validator::make([$group => [$code => $amount]], ["$group.$code" => ['required', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/']])->validate();
                if ($group === 'balances') {
                    $balances[$code] = Bolivianos::cents($amount);
                } elseif (filled($raw)) {
                    $second[$code] = Bolivianos::cents($amount);
                }
            }
        }
        $count = null;
        if (isset($v['cash_count'])) {
            $result = app(CashCountCalculator::class)->recalculateSnapshot($v['cash_count']);
            if (! $result['valid'] || $result['snapshot']['total_cents'] !== ($balances['efectivo'] ?? null)) {
                throw ValidationException::withMessages(['cash_count' => 'El arqueo no coincide con el efectivo de apertura. Revísalo o ingresa el efectivo manualmente.']);
            }
            $count = $result['snapshot'];
        }
        $reason = trim($v['reason'] ?? '');
        $payload = [$accessId, $balances, $second, $reason];
        if ($count !== null) {
            $payload[] = $count;
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $captureSchedule = app(CashBoxScheduleSafety::class)->attempt(fn (): bool => app(ScheduleConfiguration::class)->allows($access->cash_box_id), false);
        $session = DB::transaction(function () use ($user, $access, $v, $balances, $second, $reason, $hash, $count, $captureSchedule): CashBoxSession {
            $priorCustody = CashBoxSession::where('cash_box_id', $access->cash_box_id)->latest('id')->first();
            $users = User::whereIn('id', array_unique(array_filter([$user->id, $priorCustody?->user_id])))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $fresh = $users[$user->id];
            Gate::forUser($fresh)->authorize('cajas.operar');
            $box = CashBox::whereKey($access->cash_box_id)->lockForUpdate()->firstOrFail();
            $access = CashBoxAccess::whereKey($access->id)->lockForUpdate()->firstOrFail();
            if ($session = CashBoxSession::where('request_key', $v['request_key'])->first()) {
                if ($session->user_id !== $user->id || $session->cash_box_id !== $box->id) {
                    throw new AuthorizationException;
                }
                if ($session->payload_hash !== $hash || $session->status !== 'ABIERTA') {
                    throw ValidationException::withMessages(['reason' => 'La solicitud ya fue utilizada. Ingresa nuevamente con tu PIN.']);
                }

                return $session;
            }
            $active = CashBoxSession::where('cash_box_id', $box->id)->whereNotNull('active_slot')->first();
            if ($active) {
                if ($active->user_id !== $user->id || $active->status !== 'ABIERTA') {
                    throw new AuthorizationException('La caja solo puede recuperarla su titular.');
                }
                $access->update(['cash_box_session_id' => $active->id]);

                return $active;
            }
            if ($access->cash_box_session_id || ! $box->is_active) {
                throw ValidationException::withMessages(['reason' => 'Ingresa nuevamente con tu PIN para consultar la caja.']);
            }
            $now = now('UTC')->toImmutable();
            if ($access->verified_at->setTimezone('America/La_Paz')->toDateString() !== $now->setTimezone('America/La_Paz')->toDateString()) {
                throw ValidationException::withMessages(['reason' => 'Valida nuevamente tu PIN para esta fecha.']);
            }
            $proof = DB::table('user_audits')->where('id', $access->pin_audit_id)->where('actor_user_id', $user->id)
                ->where('subject_user_id', $user->id)->where('action', 'pin_verified')->exists();
            if (! $proof) {
                throw new AuthorizationException;
            }
            $config = CashBoxConfiguration::where('cash_box_id', $box->id)->latest('id')->firstOrFail();
            if ($config->id !== $access->configuration_id) {
                throw ValidationException::withMessages(['reason' => 'Las cuentas o turnos cambiaron. Ingresa nuevamente con tu PIN.']);
            }
            $previous = CashBoxSession::where('cash_box_id', $box->id)->latest('id')->first();
            if ($previous && (! $previous->released_at || ! in_array($previous->status, ['CERRADA', 'CERRADA_CON_INCIDENCIAS'], true)
                || CashBoxComponents::handoverReference($config->accounts, $previous->handover_balances ?? []) !== $access->expected_balances)) {
                throw ValidationException::withMessages(['reason' => 'Los saldos autorizados cambiaron o la caja aún no fue liberada. Ingresa nuevamente.']);
            }
            if ($previous?->id !== $priorCustody?->id) {
                throw ValidationException::withMessages(['reason' => 'La entrega anterior cambió. Ingresa nuevamente para recibir los saldos vigentes.']);
            }
            $firstCount = ! $previous && $config->first_count_pending;
            $requiredTotal = $previous ? array_sum($access->expected_balances)
                : app(CashBoxOperatingCapital::class)->amount($box->id, $config->base_capital_cents) + array_sum(app(ReservedCashBoxFunds::class)->forBox($box->id));
            if (array_sum($balances) !== $requiredTotal) {
                throw ValidationException::withMessages(['opening_total' => 'No se puede abrir: el total debe ser exactamente Bs '.Bolivianos::format($requiredTotal).($previous ? ', según la entrega del cierre anterior.' : ', según el capital configurado.').' Completa o corrige los saldos antes de abrir.']);
            }
            $expected = $firstCount ? $balances : $access->expected_balances;
            if (array_sum($balances) !== array_sum($expected)) {
                throw ValidationException::withMessages(['opening_total' => 'La referencia de apertura no coincide con el capital configurado. Administración debe revisar la configuración antes de abrir.']);
            }
            $difference = array_sum($balances) - array_sum($expected);
            if ($difference !== 0 && $reason === '') {
                $reason = $difference < 0 ? 'Faltante detectado al recibir la caja.' : 'Sobrante detectado al recibir la caja.';
            }
            $threshold = (int) $this->rules->current()['high_difference_cents'];
            $components = [];
            foreach ($balances as $code => $actual) {
                $delta = $actual - $expected[$code];
                $components[$code] = ['expected' => $expected[$code], 'actual' => $actual, 'difference' => $delta, 'second' => $second[$code] ?? null];
            }
            if ($difference !== 0 && mb_strlen($reason) < 3) {
                throw ValidationException::withMessages(['reason' => 'Describe la diferencia total encontrada al recibir la caja.']);
            }
            $comparison = ['initial_count' => $firstCount, 'difference_cents' => $difference, 'threshold_cents' => $threshold, 'components' => $components, 'reason' => $reason,
                'redistribution_only' => $difference === 0 && $balances !== $expected,
                'count_details' => ['method' => $count === null ? 'MANUAL' : 'ARQUEO', 'cash_count' => $count]];
            $comparison['schedule_capture_requested'] = $captureSchedule;
            $session = CashBoxSession::create(['cash_box_id' => $box->id, 'user_id' => $user->id, 'configuration_id' => $config->id,
                'previous_session_id' => $previous?->id, 'scheduled_workday_id' => $access->scheduled_workday_id,
                'status' => 'ABIERTA', 'active_slot' => 1, 'base_capital_cents' => $config->base_capital_cents, 'operating_capital_cents' => app(CashBoxOperatingCapital::class)->amount($box->id, $config->base_capital_cents),
                'current_balances' => $balances, 'opening_balances' => $balances, 'expected_balances' => $expected, 'comparison' => $comparison,
                'opened_at' => $now, 'request_key' => $v['request_key'], 'payload_hash' => $hash]);
            $access->update(['cash_box_session_id' => $session->id]);
            DB::table('cash_box_session_events')->insert(['cash_box_session_id' => $session->id, 'actor_user_id' => $user->id,
                'access_id' => $access->id, 'action' => 'ABIERTA', 'created_at' => $now]);
            app(RecordCashBoxReceiptAction::class)->execute($fresh, $session);

            app(RecordCashBoxTelegramNoticeAction::class)->opening($session->id);

            return $session;
        });
        app(CashBoxScheduleSafety::class)->attempt(function () use ($access, $session): void {
            if (($session->comparison['schedule_capture_requested'] ?? false) && app(ScheduleConfiguration::class)->allows($access->cash_box_id)) {
                CashBoxScheduleEvent::firstOrCreate(['source_key' => 'pin:'.$session->user_id.':'.$access->request_key],
                    ['user_id' => $session->user_id, 'kind' => 'PIN', 'pin_audit_id' => $access->pin_audit_id, 'occurred_at' => $access->verified_at]);
                CashBoxScheduleEvent::firstOrCreate(['source_key' => 'opening:'.$session->id],
                    ['user_id' => $session->user_id, 'kind' => 'OPENING', 'pin_audit_id' => $access->pin_audit_id, 'access_id' => $access->id,
                        'cash_box_session_id' => $session->id, 'family' => CashBox::findOrFail($session->cash_box_id)->family(), 'occurred_at' => $session->opened_at]);
                $eventId = CashBoxScheduleEvent::where('cash_box_session_id', $session->id)->value('id');
                if ($eventId) {
                    app(ProcessCashBoxScheduleEventAction::class)->safely($eventId);
                }
            }

        });

        return $session->fresh();
    }
}
