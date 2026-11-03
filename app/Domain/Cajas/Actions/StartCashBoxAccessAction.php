<?php

namespace App\Domain\Cajas\Actions;

use App\Domain\Cajas\Data\CashBoxComponents;
use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxAccess;
use App\Domain\Cajas\Models\CashBoxConfiguration;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Domain\ControlHorario\Actions\CaptureCashBoxContinuityAction;
use App\Domain\ControlHorario\Actions\ProcessCashBoxScheduleEventAction;
use App\Domain\ControlHorario\Models\CashBoxScheduleEvent;
use App\Domain\ControlHorario\Models\ScheduledWorkday;
use App\Domain\ControlHorario\Queries\ScheduleConfiguration;
use App\Domain\Usuarios\Actions\VerifyPersonalPinAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class StartCashBoxAccessAction
{
    public function __construct(private VerifyPersonalPinAction $pin) {}

    public function execute(User $actor, CashBox $box, #[SensitiveParameter] string $pin, string $requestKey): CashBoxAccess
    {
        $user = User::findOrFail($actor->id);
        Gate::forUser($user)->authorize('cajas.operar');
        Validator::make(['request_key' => $requestKey], ['request_key' => ['required', 'uuid']])->validate();
        $proof = $this->pin->verifyAndReturnAudit($user, $pin);
        $instant = now('UTC')->toImmutable();
        $day = null;
        if (app(ScheduleConfiguration::class)->allows($box->id)) {
            $event = CashBoxScheduleEvent::firstOrCreate(['source_key' => 'pin:'.$user->id.':'.$requestKey],
                ['user_id' => $user->id, 'kind' => 'PIN', 'pin_audit_id' => $proof, 'occurred_at' => $instant]);
            $proof = $event->pin_audit_id;
            $instant = $event->occurred_at;
            app(ProcessCashBoxScheduleEventAction::class)->safely($event->id);
            $event->refresh();
            $day = $event->scheduled_workday_id ? ScheduledWorkday::find($event->scheduled_workday_id) : null;
            app(CaptureCashBoxContinuityAction::class)->afterVerifiedPin($user);
        }

        // Attendance is committed even if exclusive ownership subsequently rejects this box.
        return DB::transaction(function () use ($user, $box, $requestKey, $proof, $instant, $day): CashBoxAccess {
            $currentUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($currentUser)->authorize('cajas.operar');
            $current = CashBox::whereKey($box->id)->lockForUpdate()->firstOrFail();
            $session = CashBoxSession::where('cash_box_id', $box->id)->whereNotNull('active_slot')->first();
            if ($session && $session->user_id === $user->id && $session->status !== 'ABIERTA') {
                throw ValidationException::withMessages(['pin' => 'Esta caja no está disponible para abrir otra sesión. Consulta con Administración.']);
            }
            if ($session && ($session->user_id !== $user->id || $session->status !== 'ABIERTA')) {
                throw new AuthorizationException('Esta caja no está disponible para tu ingreso. La sesión pertenece exclusivamente a su titular.');
            }
            if (! $session && ! $current->is_active) {
                throw ValidationException::withMessages(['pin' => 'Esta caja está inactiva.']);
            }
            $config = $session ? CashBoxConfiguration::findOrFail($session->configuration_id)
                : CashBoxConfiguration::where('cash_box_id', $box->id)->latest('id')->first();
            if (! $config) {
                throw ValidationException::withMessages(['pin' => 'Falta la configuración inicial. Administración debe ir a Configuraciones → Configuración de cajas → Cuentas y turnos y guardarla. Los saldos se cuentan al abrir; los horarios ya vienen precargados.']);
            }
            $previous = CashBoxSession::where('cash_box_id', $box->id)->latest('id')->first();
            if (! $session && $previous && (! $previous->released_at || $previous->handover_balances === null || ! in_array($previous->status, ['CERRADA', 'CERRADA_CON_INCIDENCIAS'], true))) {
                throw ValidationException::withMessages(['pin' => 'La caja espera su cierre y liberación administrativa.']);
            }
            if ($existing = CashBoxAccess::where('request_key', $requestKey)->first()) {
                if ($existing->user_id !== $user->id || $existing->cash_box_id !== $box->id) {
                    throw new AuthorizationException;
                }

                return $existing;
            }
            $expected = $session?->expected_balances ?? ($previous
                ? CashBoxComponents::handoverReference($config->accounts, $previous->handover_balances)
                : array_map(fn (array $account): int => $account['initial_cents'], $config->accounts));
            $access = CashBoxAccess::create(['cash_box_id' => $box->id, 'user_id' => $user->id, 'pin_audit_id' => $proof,
                'configuration_id' => $config->id, 'cash_box_session_id' => $session?->id, 'scheduled_workday_id' => $day?->id,
                'expected_balances' => $expected, 'verified_at' => $instant, 'request_key' => $requestKey]);
            if ($session) {
                DB::table('cash_box_session_events')->insert(['cash_box_session_id' => $session->id, 'actor_user_id' => $user->id,
                    'access_id' => $access->id, 'action' => 'RECUPERADA', 'created_at' => now()]);
            }

            return $access;
        });
    }
}
